<?php

namespace App\Services\Jumia;

use App\Models\Client as Customer;
use App\Models\JumiaLog;
use App\Models\JumiaMapping;
use App\Models\JumiaSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Jumia Seller Center sync engine, same cursor-batch contract as the other
 * connectors. Product identity is the SellerSku (= Stocky product code):
 * price/stock pushes are keyed by SKU with no product mapping needed, and go
 * through async ProductUpdate feeds (the FeedId is logged; per-item rejects
 * appear in Seller Center's feed status). Jumia has no webhooks — orders are
 * pulled (idempotent via order mappings) and become sales (Ref JM-<number>).
 */
class SyncService
{
    private JumiaSetting $settings;
    private Client $client;

    public function __construct(JumiaSetting $settings, ?Client $client = null)
    {
        $this->settings = $settings;
        $this->client = $client ?: Client::make($settings);
    }

    public static function make(?JumiaSetting $settings = null): self
    {
        return new self($settings ?: JumiaSetting::current());
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    public function testConnection(): array
    {
        try {
            Client::body($this->client->call('GetOrders', ['Limit' => 1, 'Offset' => 0]));

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------
    // Price & stock push (async ProductUpdate feed, keyed by SellerSku)
    // -----------------------------------------------------------------

    public function pushPriceStock(int $startAfterId = 0, int $batch = 100): array
    {
        $products = Product::whereNull('deleted_at')
            ->where('id', '>', $startAfterId)
            ->where('code', '!=', '')
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $rows = [];
        $skipped = 0;
        $lastId = $startAfterId;
        foreach ($products as $product) {
            $lastId = (int) $product->id;
            if ((int) $product->is_variant === 1) {
                // Variant parents have no own price/stock; their variants are
                // pushed by variant SKU below.
                foreach (ProductVariant::whereNull('deleted_at')->where('product_id', $product->id)->get() as $variant) {
                    if (trim((string) $variant->code) === '') {
                        $skipped++;
                        continue;
                    }
                    $rows[] = [
                        'sku' => (string) $variant->code,
                        'price' => (float) $variant->price,
                        'qty' => (int) round($this->localQuantity((int) $product->id, (int) $variant->id)),
                    ];
                }
                continue;
            }
            $rows[] = [
                'sku' => (string) $product->code,
                'price' => (float) $product->price,
                'qty' => (int) round($this->localQuantity((int) $product->id, null)),
            ];
        }

        if (! $rows) {
            return [
                'ok' => true, 'processed' => $products->count(), 'updated' => 0, 'skipped' => $skipped,
                'failed' => 0, 'errors' => [], 'last_id' => $lastId, 'has_more' => $products->count() === $batch,
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?><Request>';
        foreach ($rows as $row) {
            $xml .= '<Product><SellerSku>'.htmlspecialchars($row['sku'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</SellerSku>'
                .'<Price>'.number_format($row['price'], 2, '.', '').'</Price>'
                .'<Quantity>'.$row['qty'].'</Quantity></Product>';
        }
        $xml .= '</Request>';

        try {
            $body = Client::body($this->client->feed('ProductUpdate', $xml));
            $feedId = (string) ($body['FeedId'] ?? ($body['Feed'] ?? ''));
            $this->settings->forceFill(['last_sync_at' => now()])->save();
            $this->log('price_stock.push', 'info', 'Feed submitted for '.count($rows).' SKUs'.($feedId !== '' ? " (feed {$feedId})" : ''), [
                'feed_id' => $feedId ?: null,
            ]);

            return [
                'ok' => true,
                'processed' => $products->count(),
                'updated' => count($rows),
                'skipped' => $skipped,
                'failed' => 0,
                'errors' => [],
                'last_id' => $lastId,
                'has_more' => $products->count() === $batch,
            ];
        } catch (\Throwable $e) {
            $this->log('price_stock.push', 'error', $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function localQuantity(int $productId, ?int $variantId): float
    {
        $query = product_warehouse::where('product_id', $productId)
            ->whereNull('deleted_at');

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        if ($this->settings->warehouse_id) {
            $query->where('warehouse_id', $this->settings->warehouse_id);
        }

        return (float) $query->sum('qte');
    }

    // -----------------------------------------------------------------
    // Orders
    // -----------------------------------------------------------------

    public function pullOrders(int $page, int $perPage, int $userId, ?int $warehouseId): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        try {
            $body = Client::body($this->client->call('GetOrders', [
                'Limit' => $perPage,
                'Offset' => $offset,
                'SortBy' => 'created_at',
                'SortDirection' => 'DESC',
            ]));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $orders = Client::listOf($body, 'Orders', 'Order');
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($orders as $order) {
            $order = (array) $order;
            $jumiaOrderId = (int) ($order['OrderId'] ?? 0);
            if (! $jumiaOrderId) {
                continue;
            }
            try {
                $result = $this->importOrder($order, $userId, $warehouseId);
                $result === 'imported' ? $imported++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['jumia_order_id' => $jumiaOrderId, 'error' => $e->getMessage()];
            }
        }

        $hasMore = count($orders) === $perPage;

        $this->log('orders.pull', $failed ? 'warning' : 'info', 'Orders pull page '.$page.": {$imported} imported, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'next_page_info' => $hasMore ? $page + 1 : null,
            'has_more' => $hasMore,
        ];
    }

    /** @return string 'imported' | 'skipped' */
    private function importOrder(array $order, int $userId, ?int $warehouseId): string
    {
        $jumiaOrderId = (int) ($order['OrderId'] ?? 0);
        if (JumiaMapping::localId(JumiaMapping::TYPE_ORDER, $jumiaOrderId)) {
            return 'skipped';
        }

        $statuses = array_map('strtolower', array_map('strval',
            Client::listOf((array) $order, 'Statuses', 'Status') ?: [(string) ($order['Statuses']['Status'] ?? '')]
        ));
        if (array_intersect($statuses, ['canceled', 'cancelled', 'returned', 'failed'])) {
            return 'skipped';
        }

        $warehouseId = $warehouseId
            ?: ($this->settings->warehouse_id
                ?: (int) Warehouse::whereNull('deleted_at')->orderBy('id')->value('id'));
        if (! $warehouseId) {
            throw new \RuntimeException('No warehouse available for order import');
        }

        // Line items come from a second call.
        $itemsBody = Client::body($this->client->call('GetOrderItems', ['OrderId' => $jumiaOrderId]));
        $items = Client::listOf($itemsBody, 'OrderItems', 'OrderItem');

        $resolved = [];
        $unresolved = [];
        foreach ($items as $item) {
            $item = (array) $item;
            $match = $this->resolveLineItem($item);
            if ($match) {
                $resolved[] = ['item' => $item] + $match;
            } else {
                $unresolved[] = trim((string) ($item['Sku'] ?? ($item['Name'] ?? 'item')));
            }
        }
        if (! $resolved) {
            $this->log('orders.import', 'warning', 'Order skipped — no line item matched a local product', [
                'jumia_order_id' => $jumiaOrderId,
                'unmatched' => $unresolved,
            ]);

            return 'skipped';
        }

        $grandTotal = (float) ($order['Price'] ?? 0);
        $delivered = in_array('delivered', $statuses, true);
        $orderStatut = $delivered ? 'completed'
            : (array_intersect($statuses, ['shipped', 'ready_to_ship']) ? 'pending' : 'ordered');
        // COD-dominant marketplace: money is only certain once delivered.
        $paymentStatut = $delivered ? 'paid' : 'unpaid';

        $createdAt = now();
        try {
            if (! empty($order['CreatedAt'])) {
                $createdAt = \Carbon\Carbon::parse((string) $order['CreatedAt']);
            }
        } catch (\Throwable $e) {
            // Keep now()
        }

        $orderNumber = trim((string) ($order['OrderNumber'] ?? '')) ?: (string) $jumiaOrderId;
        $clientId = $this->resolveClientId($order);

        DB::transaction(function () use (
            $order, $jumiaOrderId, $orderNumber, $createdAt, $clientId, $warehouseId, $userId,
            $grandTotal, $paymentStatut, $orderStatut, $resolved, $unresolved
        ) {
            $sale = Sale::create([
                'date' => $createdAt->format('Y-m-d'),
                'time' => $createdAt->format('H:i:s'),
                'Ref' => 'JM-'.$orderNumber,
                'is_pos' => 0,
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'user_id' => $userId,
                'tax_rate' => 0,
                'TaxNet' => 0,
                'discount' => 0,
                'shipping' => 0,
                'GrandTotal' => $grandTotal,
                'paid_amount' => $paymentStatut === 'paid' ? $grandTotal : 0,
                'payment_statut' => $paymentStatut,
                'statut' => $orderStatut,
                'notes' => 'Imported from Jumia — order '.$orderNumber
                    .(! empty($order['PaymentMethod']) ? ' ('.$order['PaymentMethod'].')' : '')
                    .(empty($unresolved) ? '' : ' — unmatched items: '.json_encode($unresolved, JSON_UNESCAPED_UNICODE)),
            ]);

            foreach ($resolved as $entry) {
                $item = (array) $entry['item'];
                $qty = 1.0; // Seller Center answers one OrderItem row PER UNIT
                $price = (float) ($item['PaidPrice'] ?? ($item['ItemPrice'] ?? 0));

                SaleDetail::create([
                    'date' => $createdAt->format('Y-m-d'),
                    'sale_id' => $sale->id,
                    'product_id' => $entry['product_id'],
                    'product_variant_id' => $entry['variant_id'],
                    'quantity' => $qty,
                    'price' => $price,
                    'TaxNet' => 0,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_method' => '2',
                    'total' => round($price * $qty, 2),
                ]);

                // Decrement stock in the receiving warehouse
                $stockQuery = product_warehouse::where('product_id', $entry['product_id'])
                    ->where('warehouse_id', $warehouseId)
                    ->whereNull('deleted_at');
                if ($entry['variant_id']) {
                    $stockQuery->where('product_variant_id', $entry['variant_id']);
                } else {
                    $stockQuery->whereNull('product_variant_id');
                }
                $stock = $stockQuery->first();
                if ($stock) {
                    $stock->qte = (float) $stock->qte - $qty;
                    $stock->save();
                }
            }

            JumiaMapping::put(JumiaMapping::TYPE_ORDER, (int) $sale->id, $jumiaOrderId, [
                'order_number' => $orderNumber,
            ]);
        }, 3);

        return 'imported';
    }

    /** @return array{product_id:int, variant_id:?int}|null */
    private function resolveLineItem(array $item): ?array
    {
        $sku = trim((string) ($item['Sku'] ?? ''));
        if ($sku === '') {
            return null;
        }

        $variant = ProductVariant::whereNull('deleted_at')->where('code', $sku)->first();
        if ($variant) {
            return ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id];
        }
        $product = Product::whereNull('deleted_at')->where('code', $sku)->first();
        if ($product) {
            return ['product_id' => (int) $product->id, 'variant_id' => null];
        }

        return null;
    }

    /** No stable customer id in Seller Center orders: match by phone, else create. */
    private function resolveClientId(array $order): int
    {
        $name = trim(((string) ($order['CustomerFirstName'] ?? '')).' '.((string) ($order['CustomerLastName'] ?? '')));
        $shipping = (array) ($order['AddressShipping'] ?? []);
        $phone = trim((string) ($shipping['Phone'] ?? ($shipping['Phone2'] ?? '')));

        $client = null;
        if ($phone !== '') {
            $client = Customer::whereNull('deleted_at')->where('phone', $phone)->first();
        }
        if (! $client && $name !== '') {
            $client = Customer::whereNull('deleted_at')->where('name', $name)->first();
        }

        if (! $client) {
            $client = new Customer();
            $client->code = $this->nextCustomerCode();
            $client->name = $name !== '' ? $name : 'Jumia customer';
            $client->phone = $phone !== '' ? $phone : null;
            $client->city = trim((string) ($shipping['City'] ?? '')) ?: null;
            $client->country = trim((string) ($shipping['Country'] ?? '')) ?: null;
            $client->save();
        }

        return (int) $client->id;
    }

    /** Same convention as the Shopify/Salla modules. */
    private function nextCustomerCode(): int
    {
        $last = DB::table('clients')->latest('id')->first();

        return $last ? ((int) $last->code + 1) : 1;
    }

    // -----------------------------------------------------------------
    // Stats / logging
    // -----------------------------------------------------------------

    public function stats(): array
    {
        return [
            'products_total' => Product::whereNull('deleted_at')->count(),
            'orders_imported' => JumiaMapping::where('entity_type', JumiaMapping::TYPE_ORDER)->count(),
        ];
    }

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            JumiaLog::create([
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a sync
        }
    }
}
