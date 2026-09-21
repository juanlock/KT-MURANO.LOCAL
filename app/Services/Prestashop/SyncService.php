<?php

namespace App\Services\Prestashop;

use App\Models\Client as Customer;
use App\Models\PrestashopLog;
use App\Models\PrestashopMapping;
use App\Models\PrestashopSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PrestaShop sync engine (single store per tenant), same contract as the
 * Salla module: cursor-batched methods driven by the frontend loop.
 *
 * Product identity is the SKU (Stocky `products.code` <-> PrestaShop
 * `reference`). V1 semantics:
 *  - product PUSH creates missing simple products and SKIPS mapped ones
 *    (PrestaShop's PUT wants the full object back — updates stay in the shop);
 *  - stock pushes continuously through stock_availables;
 *  - PrestaShop combinations (variants) are not pushed and are imported as
 *    their parent product; order rows still match variants by reference;
 *  - PrestaShop has no webhooks, so orders come in through the pull button
 *    (idempotent — already-imported orders are skipped).
 */
class SyncService
{
    private PrestashopSetting $settings;
    private Client $client;

    public function __construct(PrestashopSetting $settings, ?Client $client = null)
    {
        $this->settings = $settings;
        $this->client = $client ?: Client::make($settings);
    }

    public static function make(?PrestashopSetting $settings = null): self
    {
        return new self($settings ?: PrestashopSetting::current());
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    public function testConnection(): array
    {
        $res = $this->client->get('products', ['limit' => 1, 'display' => '[id]']);
        if (! $res->successful()) {
            return ['ok' => false, 'status' => $res->status(), 'error' => Client::error($res)];
        }

        return ['ok' => true];
    }

    // -----------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------

    public function pushProducts(int $startAfterId = 0, int $batch = 15): array
    {
        $products = Product::whereNull('deleted_at')
            ->where('id', '>', $startAfterId)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($products as $product) {
            $lastId = (int) $product->id;

            // Mapped products are write-once (PrestaShop PUT wants the full
            // object back); variant products need the combinations API.
            if ((int) $product->is_variant === 1
                || PrestashopMapping::prestashopId(PrestashopMapping::TYPE_PRODUCT, (int) $product->id)) {
                $skipped++;
                continue;
            }

            try {
                $this->createRemoteProduct($product);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['product_id' => $product->id, 'code' => $product->code, 'error' => $e->getMessage()];
            }
        }

        $this->settings->forceFill(['last_sync_at' => now()])->save();
        $this->log('products.push', $failed ? 'warning' : 'info', "Products push batch: {$created} created, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $products->count(),
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $products->count() === $batch,
        ];
    }

    private function createRemoteProduct(Product $product): int
    {
        $langId = max(1, (int) $this->settings->default_language_id);
        $name = trim((string) $product->name) ?: 'Product '.$product->id;
        $slug = Str::slug($name) ?: 'product-'.$product->id;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><product>'
            .'<reference>'.self::x((string) $product->code).'</reference>'
            .'<price>'.number_format((float) $product->price, 6, '.', '').'</price>'
            .'<state>1</state>'
            .'<active>1</active>'
            .'<available_for_order>1</available_for_order>'
            .'<show_price>1</show_price>'
            .'<minimal_quantity>1</minimal_quantity>'
            .'<name><language id="'.$langId.'">'.self::x($name).'</language></name>'
            .'<link_rewrite><language id="'.$langId.'">'.self::x($slug).'</language></link_rewrite>'
            .'</product></prestashop>';

        $res = $this->client->postXml('products', $xml);
        if (! $res->successful()) {
            throw new \RuntimeException(Client::error($res));
        }

        $parsed = Client::parseXml((string) $res->body());
        $remoteId = (int) ($parsed['product']['id'] ?? 0);
        if (! $remoteId) {
            throw new \RuntimeException('PrestaShop returned no product id');
        }

        PrestashopMapping::put(PrestashopMapping::TYPE_PRODUCT, (int) $product->id, $remoteId, [
            'reference' => (string) $product->code,
        ]);

        // Set the initial stock right away so the shop doesn't show 0.
        $this->pushStockForProduct($product, $remoteId);

        return $remoteId;
    }

    public function pullProducts(int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $res = $this->client->get('products', [
            'display' => 'full',
            'limit' => $offset.','.$perPage,
            'sort' => '[id_ASC]',
        ]);
        if (! $res->successful()) {
            return ['ok' => false, 'error' => Client::error($res)];
        }

        $remotes = (array) ($res->json()['products'] ?? []);
        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($remotes as $remote) {
            try {
                $result = $this->importRemoteProduct((array) $remote);
                $result === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['prestashop_product_id' => $remote['id'] ?? null, 'error' => $e->getMessage()];
            }
        }

        $hasMore = count($remotes) === $perPage;

        $this->log('products.pull', $failed ? 'warning' : 'info', 'Products pull page '.$page.": {$created} created, {$updated} updated, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'next_page_info' => $hasMore ? $page + 1 : null,
            'has_more' => $hasMore,
        ];
    }

    private function importRemoteProduct(array $remote): string
    {
        $remoteId = (int) ($remote['id'] ?? 0);
        $reference = trim((string) ($remote['reference'] ?? ''));
        $name = trim(self::lang($remote['name'] ?? '')) ?: 'PrestaShop product';
        $price = (float) ($remote['price'] ?? 0);

        $localId = PrestashopMapping::localId(PrestashopMapping::TYPE_PRODUCT, $remoteId);
        $product = $localId ? Product::find($localId) : null;

        if (! $product && $reference !== '') {
            $product = Product::whereNull('deleted_at')->where('code', $reference)->first();
        }

        $isNew = false;
        if (! $product) {
            $isNew = true;
            $product = new Product();
            $product->type = 'is_single';
            $product->name = $name;
            $product->code = $reference !== '' ? $reference : 'PS-'.$remoteId;
            $product->Type_barcode = 'CODE128';
            $product->price = $price;
            $product->cost = 0;
            $product->wholesale_price = (float) ($remote['wholesale_price'] ?? 0);
            $product->min_price = 0;
            $product->category_id = $this->resolveCategoryId();
            $product->unit_id = $this->defaultUnitId();
            $product->unit_sale_id = $product->unit_id;
            $product->unit_purchase_id = $product->unit_id;
            $product->tax_method = '1';
            $product->stock_alert = 0;
            $product->is_variant = 0;
            $product->is_active = ((string) ($remote['active'] ?? '1')) === '1' ? 1 : 0;
            $product->note = trim(strip_tags(self::lang($remote['description_short'] ?? ''))) ?: null;
            $product->save();

            foreach (Warehouse::whereNull('deleted_at')->pluck('id') as $warehouseId) {
                product_warehouse::firstOrCreate([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => null,
                ], ['qte' => 0, 'manage_stock' => 1]);
            }
        } else {
            $product->name = $name;
            if ($price > 0 && (int) $product->is_variant !== 1) {
                $product->price = $price;
            }
            $product->save();
        }

        PrestashopMapping::put(PrestashopMapping::TYPE_PRODUCT, (int) $product->id, $remoteId, [
            'reference' => $reference !== '' ? $reference : null,
        ]);

        return $isNew ? 'created' : 'updated';
    }

    // -----------------------------------------------------------------
    // Inventory (stock_availables)
    // -----------------------------------------------------------------

    public function pushInventory(int $startAfterId = 0, int $batch = 25): array
    {
        $mappings = PrestashopMapping::where('entity_type', PrestashopMapping::TYPE_PRODUCT)
            ->where('id', '>', $startAfterId)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $updated = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($mappings as $mapping) {
            $lastId = (int) $mapping->id;
            $product = Product::find($mapping->local_id);
            if (! $product || (int) $product->is_variant === 1) {
                $skipped++;
                continue;
            }

            try {
                $this->pushStockForProduct($product, (int) $mapping->prestashop_id);
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['mapping_id' => $mapping->id, 'code' => $product->code, 'error' => $e->getMessage()];
            }
        }

        $this->log('inventory.push', $failed ? 'warning' : 'info', "Inventory push batch: {$updated} updated, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $mappings->count(),
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $mappings->count() === $batch,
        ];
    }

    /**
     * Stock lives on the stock_available resource: find the row for the
     * product (attribute 0 = the product itself), echo its fields back with
     * the new quantity (PrestaShop PUT requires the full object).
     */
    private function pushStockForProduct(Product $product, int $remoteProductId): void
    {
        $res = $this->client->get('stock_availables', [
            'filter[id_product]' => $remoteProductId,
            'filter[id_product_attribute]' => 0,
            'display' => 'full',
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('stock_available lookup failed: '.Client::error($res));
        }

        $row = ((array) ($res->json()['stock_availables'] ?? []))[0] ?? null;
        if (! is_array($row) || empty($row['id'])) {
            throw new \RuntimeException('No stock_available row for product '.$remoteProductId);
        }

        $qty = (int) round($this->localQuantity((int) $product->id));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><stock_available>'
            .'<id>'.(int) $row['id'].'</id>'
            .'<id_product>'.(int) ($row['id_product'] ?? $remoteProductId).'</id_product>'
            .'<id_product_attribute>'.(int) ($row['id_product_attribute'] ?? 0).'</id_product_attribute>'
            .'<id_shop>'.(int) ($row['id_shop'] ?? 1).'</id_shop>'
            .'<id_shop_group>'.(int) ($row['id_shop_group'] ?? 0).'</id_shop_group>'
            .'<quantity>'.$qty.'</quantity>'
            .'<depends_on_stock>'.(int) ($row['depends_on_stock'] ?? 0).'</depends_on_stock>'
            .'<out_of_stock>'.(int) ($row['out_of_stock'] ?? 2).'</out_of_stock>'
            .'</stock_available></prestashop>';

        $put = $this->client->putXml('stock_availables/'.(int) $row['id'], $xml);
        if (! $put->successful()) {
            throw new \RuntimeException(Client::error($put));
        }
    }

    private function localQuantity(int $productId): float
    {
        $query = product_warehouse::where('product_id', $productId)
            ->whereNull('deleted_at')
            ->whereNull('product_variant_id');

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
        $res = $this->client->get('orders', [
            'display' => 'full',
            'limit' => $offset.','.$perPage,
            'sort' => '[id_DESC]',
        ]);
        if (! $res->successful()) {
            return ['ok' => false, 'error' => Client::error($res)];
        }

        $remotes = (array) ($res->json()['orders'] ?? []);
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($remotes as $order) {
            $order = (array) $order;
            $remoteOrderId = (int) ($order['id'] ?? 0);
            if (! $remoteOrderId) {
                continue;
            }
            try {
                $result = $this->importOrderPayload($order, $userId, $warehouseId);
                $result === 'imported' ? $imported++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['prestashop_order_id' => $remoteOrderId, 'error' => $e->getMessage()];
            }
        }

        $hasMore = count($remotes) === $perPage;

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
    public function importOrderPayload(array $order, int $userId, ?int $warehouseId): string
    {
        $remoteOrderId = (int) ($order['id'] ?? 0);
        if (! $remoteOrderId || PrestashopMapping::localId(PrestashopMapping::TYPE_ORDER, $remoteOrderId)) {
            return 'skipped';
        }

        // Order states: 6 = canceled, 7 = refunded, 8 = payment error.
        $stateId = (int) ($order['current_state'] ?? 0);
        if (in_array($stateId, [6, 7, 8], true)) {
            return 'skipped';
        }

        $warehouseId = $warehouseId
            ?: ($this->settings->warehouse_id
                ?: (int) Warehouse::whereNull('deleted_at')->orderBy('id')->value('id'));
        if (! $warehouseId) {
            throw new \RuntimeException('No warehouse available for order import');
        }

        $clientId = $this->resolveClientId((int) ($order['id_customer'] ?? 0));

        $rows = (array) (($order['associations']['order_rows'] ?? []) ?: []);
        $resolved = [];
        $unresolved = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $match = $this->resolveLineItem($row);
            if ($match) {
                $resolved[] = ['row' => $row] + $match;
            } else {
                $unresolved[] = trim((string) ($row['product_reference'] ?? ($row['product_name'] ?? 'item')));
            }
        }
        if (! $resolved) {
            $this->log('orders.import', 'warning', 'Order skipped — no line item matched a local product', [
                'prestashop_order_id' => $remoteOrderId,
                'unmatched' => $unresolved,
            ]);

            return 'skipped';
        }

        $grandTotal = (float) ($order['total_paid_tax_incl'] ?? ($order['total_paid'] ?? 0));
        $shipping = (float) ($order['total_shipping_tax_incl'] ?? ($order['total_shipping'] ?? 0));
        $discount = (float) ($order['total_discounts_tax_incl'] ?? ($order['total_discounts'] ?? 0));
        $tax = max(0.0, (float) ($order['total_paid_tax_incl'] ?? 0) - (float) ($order['total_paid_tax_excl'] ?? 0));
        $paidReal = (float) ($order['total_paid_real'] ?? 0);
        $paymentStatut = $paidReal >= $grandTotal && $grandTotal > 0 ? 'paid' : ($paidReal > 0 ? 'partial' : 'unpaid');

        $createdAt = now();
        try {
            if (! empty($order['date_add'])) {
                $createdAt = \Carbon\Carbon::parse((string) $order['date_add']);
            }
        } catch (\Throwable $e) {
            // Keep now()
        }

        $reference = trim((string) ($order['reference'] ?? '')) ?: (string) $remoteOrderId;
        $orderStatut = $this->mapOrderState($stateId);

        DB::transaction(function () use (
            $order, $remoteOrderId, $reference, $createdAt, $clientId, $warehouseId, $userId,
            $tax, $discount, $shipping, $grandTotal, $paidReal, $paymentStatut, $orderStatut, $resolved, $unresolved
        ) {
            $sale = Sale::create([
                'date' => $createdAt->format('Y-m-d'),
                'time' => $createdAt->format('H:i:s'),
                'Ref' => 'PS-'.$reference,
                'is_pos' => 0,
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'user_id' => $userId,
                'tax_rate' => 0,
                'TaxNet' => $tax,
                'discount' => $discount,
                'shipping' => $shipping,
                'GrandTotal' => $grandTotal,
                'paid_amount' => $paymentStatut === 'paid' ? $grandTotal : $paidReal,
                'payment_statut' => $paymentStatut,
                'statut' => $orderStatut,
                'notes' => 'Imported from PrestaShop — order '.$reference
                    .(empty($unresolved) ? '' : ' — unmatched items: '.json_encode($unresolved, JSON_UNESCAPED_UNICODE)),
            ]);

            foreach ($resolved as $entry) {
                $row = (array) $entry['row'];
                $qty = (float) ($row['product_quantity'] ?? 1);
                $price = (float) ($row['unit_price_tax_incl'] ?? ($row['product_price'] ?? 0));

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

            PrestashopMapping::put(PrestashopMapping::TYPE_ORDER, (int) $sale->id, $remoteOrderId, [
                'reference' => $order['reference'] ?? null,
            ]);
        }, 3);

        return 'imported';
    }

    /** @return array{product_id:int, variant_id:?int}|null */
    private function resolveLineItem(array $row): ?array
    {
        // 1) Reference (SKU) — the canonical identity
        $reference = trim((string) ($row['product_reference'] ?? ''));
        if ($reference !== '') {
            $variant = ProductVariant::whereNull('deleted_at')->where('code', $reference)->first();
            if ($variant) {
                return ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id];
            }
            $product = Product::whereNull('deleted_at')->where('code', $reference)->first();
            if ($product) {
                return ['product_id' => (int) $product->id, 'variant_id' => null];
            }
        }

        // 2) PrestaShop product id via mapping (simple products only)
        $remoteProductId = (int) ($row['product_id'] ?? 0);
        if ($remoteProductId) {
            $localProductId = PrestashopMapping::localId(PrestashopMapping::TYPE_PRODUCT, $remoteProductId);
            if ($localProductId) {
                $product = Product::find($localProductId);
                if ($product && (int) $product->is_variant !== 1) {
                    return ['product_id' => (int) $product->id, 'variant_id' => null];
                }
            }
        }

        return null;
    }

    /** Default PrestaShop order-state ids -> Stocky sale statuses. */
    private function mapOrderState(int $stateId): string
    {
        // 5 = delivered; 4 = shipped, 3 = processing in progress.
        return match ($stateId) {
            5 => 'completed',
            3, 4 => 'pending',
            default => 'ordered',
        };
    }

    // -----------------------------------------------------------------
    // Customers
    // -----------------------------------------------------------------

    private function resolveClientId(int $remoteCustomerId): int
    {
        $localId = $remoteCustomerId
            ? PrestashopMapping::localId(PrestashopMapping::TYPE_CUSTOMER, $remoteCustomerId)
            : null;
        $client = $localId ? Customer::find($localId) : null;
        if ($client) {
            return (int) $client->id;
        }

        $remote = [];
        if ($remoteCustomerId) {
            $res = $this->client->get('customers/'.$remoteCustomerId);
            if ($res->successful()) {
                $remote = (array) ($res->json()['customer'] ?? []);
            }
        }

        $email = trim((string) ($remote['email'] ?? ''));
        if (! $client && $email !== '') {
            $client = Customer::whereNull('deleted_at')->where('email', $email)->first();
        }

        if (! $client) {
            $name = trim(((string) ($remote['firstname'] ?? '')).' '.((string) ($remote['lastname'] ?? '')));
            $client = new Customer();
            $client->code = $this->nextCustomerCode();
            $client->name = $name !== '' ? $name : ($email !== '' ? $email : 'PrestaShop customer');
            $client->email = $email !== '' ? $email : null;
            $client->save();
        }

        if ($remoteCustomerId) {
            PrestashopMapping::put(PrestashopMapping::TYPE_CUSTOMER, (int) $client->id, $remoteCustomerId);
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
    // Stats / reset / logging / helpers
    // -----------------------------------------------------------------

    public function stats(): array
    {
        $count = fn (string $type) => PrestashopMapping::where('entity_type', $type)->count();

        return [
            'products_total' => Product::whereNull('deleted_at')->count(),
            'products_mapped' => $count(PrestashopMapping::TYPE_PRODUCT),
            'customers_total' => Customer::whereNull('deleted_at')->count(),
            'customers_mapped' => $count(PrestashopMapping::TYPE_CUSTOMER),
            'orders_imported' => $count(PrestashopMapping::TYPE_ORDER),
        ];
    }

    public function resetMappings(?string $entityType = null): int
    {
        $query = PrestashopMapping::query();
        if ($entityType) {
            $query->where('entity_type', $entityType);
        } else {
            // Keep order mappings by default: they guard against duplicate sale imports
            $query->where('entity_type', '!=', PrestashopMapping::TYPE_ORDER);
        }

        return $query->delete();
    }

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            PrestashopLog::create([
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a sync
        }
    }

    private function resolveCategoryId(): int
    {
        $first = \App\Models\Category::first();
        if ($first) {
            return (int) $first->id;
        }

        return (int) \App\Models\Category::create(['code' => 'PSHOP1', 'name' => 'PrestaShop'])->id;
    }

    private function defaultUnitId(): ?int
    {
        $unit = DB::table('units')->whereNull('deleted_at')->orderBy('id')->first();

        return $unit ? (int) $unit->id : null;
    }

    /** Multilang fields arrive as strings or [{id, value}] arrays. */
    private static function lang(mixed $field): string
    {
        if (is_array($field)) {
            $first = $field[0] ?? null;
            if (is_array($first)) {
                return (string) ($first['value'] ?? '');
            }

            return (string) ($field['value'] ?? '');
        }

        return (string) $field;
    }

    private static function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
