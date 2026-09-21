<?php

namespace App\Services\Salla;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client as Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SallaLog;
use App\Models\SallaMapping;
use App\Models\SallaSetting;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Salla sync engine (single store per tenant), modeled on the Shopify module.
 *
 * All batch methods are cursor-based and bounded so they can run inside a web
 * request: the caller loops while `has_more` is true, passing back the cursor
 * (`last_id` for local pushes; for remote pulls Salla paginates by page number,
 * returned as `next_page_info` so the shared frontend loop stays unchanged).
 *
 * Product identity is SKU-first (Stocky `products.code` / `product_variants
 * .code` <-> Salla `sku`), with salla_mappings rows as the authoritative link.
 * V1 limitation: variant products are not PUSHED (Salla generates variants
 * from options server-side); they are still matched on pull and order import,
 * and their stock is pushed when a pull has recorded the Salla variant id.
 */
class SyncService
{
    private SallaSetting $settings;
    private Client $client;

    public function __construct(SallaSetting $settings, ?Client $client = null)
    {
        $this->settings = $settings;
        $this->client = $client ?: Client::make($settings);
    }

    public static function make(?SallaSetting $settings = null): self
    {
        return new self($settings ?: SallaSetting::current());
    }

    // -----------------------------------------------------------------
    // Connection / store metadata
    // -----------------------------------------------------------------

    public function testConnection(): array
    {
        $res = $this->client->get('store/info');
        if (! $res->successful()) {
            return ['ok' => false, 'status' => $res->status(), 'error' => Client::error($res)];
        }

        $store = $res->json()['data'] ?? [];

        return [
            'ok' => true,
            'store' => [
                'id' => $store['id'] ?? null,
                'name' => $store['name'] ?? null,
                'domain' => $store['domain'] ?? null,
                'plan' => $store['plan'] ?? null,
            ],
        ];
    }

    /** Persist store metadata after a successful connection. */
    public function refreshStoreInfo(): bool
    {
        $result = $this->testConnection();
        if (! ($result['ok'] ?? false)) {
            return false;
        }

        $this->settings->store_id = $result['store']['id'] ?? null;
        $this->settings->store_name = $result['store']['name'] ?? null;
        $this->settings->store_domain = $result['store']['domain'] ?? null;
        $this->settings->save();

        return true;
    }

    // -----------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------

    public function pushProducts(bool $onlyUnsynced, int $startAfterId = 0, int $batch = 15): array
    {
        $query = Product::whereNull('deleted_at')
            ->where('id', '>', $startAfterId)
            ->orderBy('id')
            ->limit($batch);

        if ($onlyUnsynced) {
            $query->whereNotIn('id', SallaMapping::where('entity_type', SallaMapping::TYPE_PRODUCT)->pluck('local_id'));
        }

        $products = $query->get();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($products as $product) {
            $lastId = (int) $product->id;

            if ((int) $product->is_variant === 1) {
                // Salla builds variants from options server-side; pushing them
                // faithfully needs the options API. Not in v1 — see class doc.
                $skipped++;
                continue;
            }

            $result = $this->pushSingleProduct($product);
            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            } else {
                $failed++;
                $errors[] = ['product_id' => $product->id, 'code' => $product->code, 'error' => $result];
            }
        }

        $this->settings->forceFill(['last_sync_at' => now()])->save();
        $this->log('products.push', $failed ? 'warning' : 'info', "Products push batch: {$created} created, {$updated} updated, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $products->count(),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $products->count() === $batch,
        ];
    }

    /** @return string 'created' | 'updated' | <error message> */
    public function pushSingleProduct(Product $product): string
    {
        $payload = $this->buildProductPayload($product);
        $sallaId = SallaMapping::sallaId(SallaMapping::TYPE_PRODUCT, (int) $product->id);

        if ($sallaId) {
            $res = $this->client->put('products/'.$sallaId, $payload);
            if ($res->status() === 404) {
                // Product deleted on Salla — drop the stale mapping and recreate.
                SallaMapping::where('entity_type', SallaMapping::TYPE_PRODUCT)
                    ->where('local_id', $product->id)->delete();
                $sallaId = null;
            } elseif (! $res->successful()) {
                return Client::error($res);
            } else {
                return 'updated';
            }
        }

        $res = $this->client->post('products', $payload);
        if (! $res->successful()) {
            return Client::error($res);
        }

        $remoteId = (int) ($res->json()['data']['id'] ?? 0);
        if ($remoteId) {
            SallaMapping::put(SallaMapping::TYPE_PRODUCT, (int) $product->id, $remoteId, [
                'sku' => (string) $product->code,
            ]);
        }

        return 'created';
    }

    private function buildProductPayload(Product $product): array
    {
        $payload = [
            'name' => (string) $product->name,
            'price' => (float) $product->price,
            'product_type' => 'product',
            'quantity' => (int) round($this->localQuantity((int) $product->id, null)),
        ];

        if (trim((string) $product->code) !== '') {
            $payload['sku'] = (string) $product->code;
        }
        $note = trim(strip_tags((string) ($product->note ?? '')));
        if ($note !== '') {
            $payload['description'] = $note;
        }

        return $payload;
    }

    public function pullProducts(int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $res = $this->client->get('products', ['page' => $page, 'per_page' => $perPage]);
        if (! $res->successful()) {
            return ['ok' => false, 'error' => Client::error($res)];
        }

        $json = (array) $res->json();
        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($json['data'] ?? [] as $remote) {
            try {
                $result = $this->importRemoteProduct((array) $remote);
                $result === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['salla_product_id' => $remote['id'] ?? null, 'error' => $e->getMessage()];
            }
        }

        $pagination = $json['pagination'] ?? [];
        $currentPage = (int) ($pagination['currentPage'] ?? $page);
        $totalPages = (int) ($pagination['totalPages'] ?? $currentPage);
        $hasMore = $currentPage < $totalPages && count($json['data'] ?? []) > 0;

        $this->log('products.pull', $failed ? 'warning' : 'info', "Products pull page {$currentPage}/{$totalPages}: {$created} created, {$updated} updated, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            // Page-number pagination surfaced under the shared cursor key.
            'next_page_info' => $hasMore ? $currentPage + 1 : null,
            'has_more' => $hasMore,
        ];
    }

    private function importRemoteProduct(array $remote): string
    {
        $sallaId = (int) ($remote['id'] ?? 0);
        $sku = trim((string) ($remote['sku'] ?? ''));
        $variants = is_array($remote['skus'] ?? null) ? $remote['skus'] : [];
        $isVariant = count($variants) > 1;

        $localId = SallaMapping::localId(SallaMapping::TYPE_PRODUCT, $sallaId);
        $product = $localId ? Product::find($localId) : null;

        // Link by SKU when not mapped yet (product SKU, then variant SKUs).
        if (! $product && $sku !== '') {
            $product = Product::whereNull('deleted_at')->where('code', $sku)->first();
        }
        if (! $product) {
            foreach ($variants as $rv) {
                $vSku = trim((string) ($rv['sku'] ?? ''));
                if ($vSku === '') {
                    continue;
                }
                $localVariant = ProductVariant::whereNull('deleted_at')->where('code', $vSku)->first();
                if ($localVariant) {
                    $product = Product::whereNull('deleted_at')->find($localVariant->product_id);
                }
                if (! $product) {
                    $product = Product::whereNull('deleted_at')->where('code', $vSku)->first();
                }
                if ($product) {
                    break;
                }
            }
        }

        $categoryId = $this->resolveCategoryId((string) ($remote['categories'][0]['name'] ?? ''));
        $brandId = $this->resolveBrandId((string) ($remote['brand']['name'] ?? ''));
        $price = self::amt($remote['price'] ?? 0);

        $isNew = false;
        if (! $product) {
            $isNew = true;
            $product = new Product();
            // 'type' (not is_variant) is the discriminator the rest of the app checks
            $product->type = $isVariant ? 'is_variant' : 'is_single';
            $product->name = (string) ($remote['name'] ?? 'Salla product');
            $product->code = $sku !== '' ? $sku : 'SLA-'.$sallaId;
            $product->Type_barcode = 'CODE128';
            $product->price = $price;
            $product->cost = 0;
            $product->wholesale_price = 0;
            $product->min_price = 0;
            $product->category_id = $categoryId;
            $product->brand_id = $brandId ?: null;
            $product->unit_id = $this->defaultUnitId();
            $product->unit_sale_id = $product->unit_id;
            $product->unit_purchase_id = $product->unit_id;
            $product->tax_method = '1';
            $product->stock_alert = 0;
            $product->is_variant = $isVariant ? 1 : 0;
            $product->is_active = (($remote['status'] ?? 'sale') !== 'hidden') ? 1 : 0;
            $product->note = trim(strip_tags((string) ($remote['description'] ?? ''))) ?: null;
            $product->save();

            if (! $isVariant) {
                foreach (Warehouse::whereNull('deleted_at')->pluck('id') as $warehouseId) {
                    product_warehouse::firstOrCreate([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                        'product_variant_id' => null,
                    ], ['qte' => 0, 'manage_stock' => 1]);
                }
            }
        } else {
            $product->name = (string) ($remote['name'] ?? $product->name);
            if ($categoryId) {
                $product->category_id = $categoryId;
            }
            if ($brandId) {
                $product->brand_id = $brandId;
            }
            if ($isVariant && (int) $product->is_variant !== 1) {
                $product->type = 'is_variant';
                $product->is_variant = 1;
            }
            if (! $isVariant && $price > 0) {
                $product->price = $price;
            }
            $product->save();
        }

        SallaMapping::put(SallaMapping::TYPE_PRODUCT, (int) $product->id, $sallaId, [
            'sku' => $sku !== '' ? $sku : null,
        ]);

        if ($isVariant) {
            foreach ($variants as $rv) {
                $remoteVariantId = (int) ($rv['id'] ?? 0);
                $vSku = trim((string) ($rv['sku'] ?? ''));

                $existingLocalId = $remoteVariantId
                    ? SallaMapping::localId(SallaMapping::TYPE_VARIANT, $remoteVariantId)
                    : null;
                $localVariant = $existingLocalId ? ProductVariant::find($existingLocalId) : null;
                if (! $localVariant && $vSku !== '') {
                    $localVariant = ProductVariant::where('product_id', $product->id)
                        ->whereNull('deleted_at')
                        ->where('code', $vSku)
                        ->first();
                }
                if (! $localVariant) {
                    $localVariant = new ProductVariant();
                    $localVariant->product_id = $product->id;
                    $localVariant->code = $vSku !== '' ? $vSku : 'SLA-'.$remoteVariantId;
                    $localVariant->qty = 0;
                    $localVariant->cost = 0;
                }
                $localVariant->name = trim((string) ($rv['name'] ?? '')) ?: ($vSku !== '' ? $vSku : 'Variant '.$remoteVariantId);
                $variantPrice = self::amt($rv['price'] ?? ($rv['regular_price'] ?? 0));
                if ($variantPrice > 0) {
                    $localVariant->price = $variantPrice;
                }
                $localVariant->save();

                foreach (Warehouse::whereNull('deleted_at')->pluck('id') as $warehouseId) {
                    product_warehouse::firstOrCreate([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                        'product_variant_id' => $localVariant->id,
                    ], ['qte' => 0, 'manage_stock' => 1]);
                }

                if ($remoteVariantId) {
                    SallaMapping::put(SallaMapping::TYPE_VARIANT, (int) $localVariant->id, $remoteVariantId, [
                        'product_salla_id' => $sallaId,
                        'sku' => $vSku !== '' ? $vSku : null,
                    ]);
                }
            }
        }

        return $isNew ? 'created' : 'updated';
    }

    // -----------------------------------------------------------------
    // Inventory
    // -----------------------------------------------------------------

    /**
     * Push local stock levels to Salla for mapped items: simple products via
     * "update product by SKU", variants via the variant-quantity endpoint
     * (needs the Salla variant id recorded by a products pull).
     */
    public function pushInventory(int $startAfterId = 0, int $batch = 30): array
    {
        $mappings = SallaMapping::whereIn('entity_type', [SallaMapping::TYPE_PRODUCT, SallaMapping::TYPE_VARIANT])
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

            if ($mapping->entity_type === SallaMapping::TYPE_PRODUCT) {
                $product = Product::find($mapping->local_id);
                if (! $product || (int) $product->is_variant === 1 || trim((string) $product->code) === '') {
                    $skipped++;
                    continue;
                }
                $qty = (int) round($this->localQuantity((int) $product->id, null));
                $res = $this->client->put('products/sku/'.rawurlencode((string) $product->code), [
                    'quantity' => $qty,
                ]);
            } else {
                $variant = ProductVariant::find($mapping->local_id);
                if (! $variant || ! $mapping->salla_id) {
                    $skipped++;
                    continue;
                }
                $qty = (int) round($this->localQuantity((int) $variant->product_id, (int) $variant->id));
                $res = $this->client->put('products/quantities/variant/'.$mapping->salla_id, [
                    'quantity' => $qty,
                ]);
            }

            if ($res->successful()) {
                $updated++;
            } else {
                $failed++;
                $errors[] = ['mapping_id' => $mapping->id, 'error' => Client::error($res)];
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
        $perPage = min(30, max(1, $perPage)); // Salla caps per_page at 30
        $res = $this->client->get('orders', ['page' => $page, 'per_page' => $perPage]);
        if (! $res->successful()) {
            return ['ok' => false, 'error' => Client::error($res)];
        }

        $json = (array) $res->json();
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($json['data'] ?? [] as $summary) {
            $sallaOrderId = (int) ($summary['id'] ?? 0);
            if (! $sallaOrderId) {
                continue;
            }
            try {
                if (SallaMapping::localId(SallaMapping::TYPE_ORDER, $sallaOrderId)) {
                    // Already imported; the status refresh happens through webhooks.
                    $skipped++;
                    continue;
                }
                $order = $this->fetchOrder($sallaOrderId);
                $result = $this->importOrderPayload($order, $userId, $warehouseId);
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['salla_order_id' => $sallaOrderId, 'error' => $e->getMessage()];
            }
        }

        $pagination = $json['pagination'] ?? [];
        $currentPage = (int) ($pagination['currentPage'] ?? $page);
        $totalPages = (int) ($pagination['totalPages'] ?? $currentPage);
        $hasMore = $currentPage < $totalPages && count($json['data'] ?? []) > 0;

        $this->log('orders.pull', $failed ? 'warning' : 'info', "Orders pull page {$currentPage}/{$totalPages}: {$imported} imported, {$updated} updated, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'next_page_info' => $hasMore ? $currentPage + 1 : null,
            'has_more' => $hasMore,
        ];
    }

    /** Order details; the list endpoint's items lack SKUs and prices. */
    private function fetchOrder(int $sallaOrderId): array
    {
        $res = $this->client->get('orders/'.$sallaOrderId);
        if (! $res->successful()) {
            throw new \RuntimeException('Order fetch failed: '.Client::error($res));
        }
        $order = (array) ($res->json()['data'] ?? []);

        if (empty($order['items'])) {
            $itemsRes = $this->client->get('orders/items', ['order_id' => $sallaOrderId, 'per_page' => 50]);
            if ($itemsRes->successful()) {
                $order['items'] = $itemsRes->json()['data'] ?? [];
            }
        }

        return $order;
    }

    /** @return string 'imported' | 'updated' | 'skipped' */
    public function importOrderPayload(array $order, int $userId, ?int $warehouseId): string
    {
        $sallaOrderId = (int) ($order['id'] ?? 0);
        if (! $sallaOrderId) {
            return 'skipped';
        }

        $statusSlug = strtolower((string) ($order['status']['slug'] ?? ''));
        $paymentStatut = $this->mapPaymentStatus($order);

        $existingSaleId = SallaMapping::localId(SallaMapping::TYPE_ORDER, $sallaOrderId);
        if ($existingSaleId) {
            $sale = Sale::find($existingSaleId);
            if ($sale) {
                $sale->statut = $this->mapOrderStatus($statusSlug);
                $sale->payment_statut = $paymentStatut;
                if ($paymentStatut === 'paid') {
                    $sale->paid_amount = self::amt($order['amounts']['total'] ?? ($order['total'] ?? 0)) ?: (float) $sale->GrandTotal;
                }
                $sale->save();
            }

            return 'updated';
        }

        if (in_array($statusSlug, ['canceled', 'cancelled', 'restored', 'restoring'], true)) {
            return 'skipped';
        }

        $warehouseId = $warehouseId
            ?: ($this->settings->warehouse_id
                ?: (int) Warehouse::whereNull('deleted_at')->orderBy('id')->value('id'));
        if (! $warehouseId) {
            throw new \RuntimeException('No warehouse available for order import');
        }

        $clientId = $this->resolveClientId((array) ($order['customer'] ?? []));

        // Resolve line items to local products
        $resolved = [];
        $unresolved = [];
        foreach ((array) ($order['items'] ?? []) as $item) {
            $match = $this->resolveLineItem((array) $item);
            if ($match) {
                $resolved[] = ['item' => $item] + $match;
            } else {
                $unresolved[] = trim((string) ($item['sku'] ?? ($item['name'] ?? 'item')));
            }
        }
        if (! $resolved) {
            $this->log('orders.import', 'warning', 'Order skipped — no line item matched a local product', [
                'salla_order_id' => $sallaOrderId,
                'unmatched' => $unresolved,
            ]);

            return 'skipped';
        }

        $grandTotal = self::amt($order['amounts']['total'] ?? ($order['total'] ?? 0));
        $tax = self::amt($order['amounts']['tax']['amount'] ?? 0);
        $shipping = self::amt($order['amounts']['shipping_cost'] ?? 0);
        $discount = 0.0;
        foreach ((array) ($order['amounts']['discounts'] ?? []) as $d) {
            $discount += self::amt(is_array($d) ? ($d['discount'] ?? ($d['amount'] ?? 0)) : $d);
        }

        $createdAt = now();
        try {
            $rawDate = $order['date']['date'] ?? null;
            if ($rawDate) {
                $createdAt = \Carbon\Carbon::parse($rawDate);
            }
        } catch (\Throwable $e) {
            // Keep now()
        }

        $referenceId = $order['reference_id'] ?? $sallaOrderId;
        $orderStatut = $this->mapOrderStatus($statusSlug);

        DB::transaction(function () use (
            $order, $sallaOrderId, $referenceId, $createdAt, $clientId, $warehouseId, $userId,
            $tax, $discount, $shipping, $grandTotal, $paymentStatut, $orderStatut, $resolved, $unresolved
        ) {
            $sale = Sale::create([
                'date' => $createdAt->format('Y-m-d'),
                'time' => $createdAt->format('H:i:s'),
                'Ref' => 'SLA-'.$referenceId,
                'is_pos' => 0,
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'user_id' => $userId,
                'tax_rate' => 0,
                'TaxNet' => $tax,
                'discount' => $discount,
                'shipping' => $shipping,
                'GrandTotal' => $grandTotal,
                'paid_amount' => $paymentStatut === 'paid' ? $grandTotal : 0,
                'payment_statut' => $paymentStatut,
                'statut' => $orderStatut,
                'notes' => 'Imported from Salla'
                    .($this->settings->store_name ? ' ('.$this->settings->store_name.')' : '')
                    .' — order #'.$referenceId
                    .(empty($unresolved) ? '' : ' — unmatched items: '.json_encode($unresolved, JSON_UNESCAPED_UNICODE)),
            ]);

            foreach ($resolved as $row) {
                $item = (array) $row['item'];
                $qty = (float) ($item['quantity'] ?? 1);
                $price = self::amt($item['amounts']['price_without_tax'] ?? 0);
                if (! $price) {
                    $lineTotal = self::amt($item['amounts']['total'] ?? 0);
                    $price = $qty > 0 ? $lineTotal / $qty : 0;
                }
                if (! $price) {
                    $price = self::amt($item['price'] ?? 0);
                }

                SaleDetail::create([
                    'date' => $createdAt->format('Y-m-d'),
                    'sale_id' => $sale->id,
                    'product_id' => $row['product_id'],
                    'product_variant_id' => $row['variant_id'],
                    'quantity' => $qty,
                    'price' => $price,
                    'TaxNet' => 0,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_method' => '2',
                    'total' => round($price * $qty, 2),
                ]);

                // Decrement stock in the receiving warehouse
                $stockQuery = product_warehouse::where('product_id', $row['product_id'])
                    ->where('warehouse_id', $warehouseId)
                    ->whereNull('deleted_at');
                if ($row['variant_id']) {
                    $stockQuery->where('product_variant_id', $row['variant_id']);
                } else {
                    $stockQuery->whereNull('product_variant_id');
                }
                $stock = $stockQuery->first();
                if ($stock) {
                    $stock->qte = (float) $stock->qte - $qty;
                    $stock->save();
                }
            }

            SallaMapping::put(SallaMapping::TYPE_ORDER, (int) $sale->id, $sallaOrderId, [
                'reference_id' => $order['reference_id'] ?? null,
            ]);
        }, 3);

        return 'imported';
    }

    /** @return array{product_id:int, variant_id:?int}|null */
    private function resolveLineItem(array $item): ?array
    {
        // 1) SKU — the canonical identity
        $sku = trim((string) ($item['sku'] ?? ''));
        if ($sku !== '') {
            $variant = ProductVariant::whereNull('deleted_at')->where('code', $sku)->first();
            if ($variant) {
                return ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id];
            }
            $product = Product::whereNull('deleted_at')->where('code', $sku)->first();
            if ($product) {
                return ['product_id' => (int) $product->id, 'variant_id' => null];
            }
        }

        // 2) Salla product id via mapping (simple products only)
        $sallaProductId = (int) ($item['product']['id'] ?? ($item['product_id'] ?? 0));
        if ($sallaProductId) {
            $localProductId = SallaMapping::localId(SallaMapping::TYPE_PRODUCT, $sallaProductId);
            if ($localProductId) {
                $product = Product::find($localProductId);
                if ($product && (int) $product->is_variant !== 1) {
                    return ['product_id' => (int) $product->id, 'variant_id' => null];
                }
            }
        }

        return null;
    }

    private function mapOrderStatus(string $slug): string
    {
        // Valid Stocky sale statuses: completed | pending | ordered
        return match ($slug) {
            'completed', 'delivered' => 'completed',
            'in_progress', 'in-progress', 'delivering', 'shipped' => 'pending',
            default => 'ordered',
        };
    }

    private function mapPaymentStatus(array $order): string
    {
        $slug = strtolower((string) ($order['status']['slug'] ?? ''));
        if ($slug === 'payment_pending' || ! empty($order['is_pending_payment'])) {
            return 'unpaid';
        }

        return 'paid';
    }

    // -----------------------------------------------------------------
    // Customers
    // -----------------------------------------------------------------

    private function resolveClientId(array $customer): int
    {
        $sallaCustomerId = (int) ($customer['id'] ?? 0);
        $email = trim((string) ($customer['email'] ?? ''));
        $phone = trim(((string) ($customer['mobile_code'] ?? '')).((string) ($customer['mobile'] ?? '')));

        $localId = $sallaCustomerId ? SallaMapping::localId(SallaMapping::TYPE_CUSTOMER, $sallaCustomerId) : null;
        $client = $localId ? Customer::find($localId) : null;

        if (! $client && $email !== '') {
            $client = Customer::whereNull('deleted_at')->where('email', $email)->first();
        }
        if (! $client && $phone !== '') {
            $client = Customer::whereNull('deleted_at')->where('phone', $phone)->first();
        }

        if (! $client) {
            $name = trim(((string) ($customer['first_name'] ?? '')).' '.((string) ($customer['last_name'] ?? '')));
            $client = new Customer();
            $client->code = $this->nextCustomerCode();
            $client->name = $name !== '' ? $name : ($email !== '' ? $email : 'Salla customer');
            $client->email = $email !== '' ? $email : null;
            $client->phone = $phone !== '' ? $phone : null;
            $client->city = trim((string) ($customer['city'] ?? '')) ?: null;
            $client->country = trim((string) ($customer['country'] ?? '')) ?: null;
            $client->save();
        }

        if ($sallaCustomerId) {
            SallaMapping::put(SallaMapping::TYPE_CUSTOMER, (int) $client->id, $sallaCustomerId);
        }

        return (int) $client->id;
    }

    /** Same convention as the Shopify module / ClientController::getNumberOrder(). */
    private function nextCustomerCode(): int
    {
        $last = DB::table('clients')->latest('id')->first();

        return $last ? ((int) $last->code + 1) : 1;
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    public function handleWebhook(string $event, array $data): void
    {
        switch ($event) {
            case 'app.store.authorize':
                // Salla "Easy Mode": the token pair arrives by webhook instead
                // of an OAuth callback. Store it and pull the store metadata.
                Client::storeTokens($this->settings, $data);
                $this->settings->enabled = true;
                $this->settings->save();
                try {
                    $this->refreshStoreInfo();
                } catch (\Throwable $e) {
                    // Store info is cosmetic; tokens are already saved.
                }
                $this->log('webhook.'.$event, 'info', 'Store authorized via webhook; tokens stored');
                break;

            case 'app.installed':
                $this->log('webhook.'.$event, 'info', 'App installed on Salla store');
                break;

            case 'app.uninstalled':
                $this->settings->enabled = false;
                $this->settings->access_token = null;
                $this->settings->refresh_token = null;
                $this->settings->save();
                $this->log('webhook.'.$event, 'warning', 'App uninstalled on Salla; connection cleared');
                break;

            case 'order.created':
            case 'order.updated':
            case 'order.status.updated':
                $userId = (int) (DB::table('users')->orderBy('id')->value('id') ?: 1);
                $result = $this->importOrderPayload($data, $userId, null);
                $this->log('webhook.'.$event, 'info', 'Order webhook processed: '.$result, [
                    'salla_order_id' => $data['id'] ?? null,
                ]);
                break;

            case 'product.updated':
                $this->applyRemoteProductUpdate($data);
                break;

            case 'product.deleted':
                SallaMapping::where('entity_type', SallaMapping::TYPE_PRODUCT)
                    ->where('salla_id', (int) ($data['id'] ?? 0))
                    ->delete();
                $this->log('webhook.product.deleted', 'info', 'Product mapping removed', [
                    'salla_product_id' => $data['id'] ?? null,
                ]);
                break;

            default:
                $this->log('webhook.'.$event, 'info', 'Unhandled webhook event received');
        }
    }

    /**
     * Only update local fields for products that are already mapped — never
     * create from a product.updated webhook (avoids echo loops after a push).
     */
    private function applyRemoteProductUpdate(array $payload): void
    {
        $sallaId = (int) ($payload['id'] ?? 0);
        $localId = $sallaId ? SallaMapping::localId(SallaMapping::TYPE_PRODUCT, $sallaId) : null;
        if (! $localId) {
            return;
        }

        $this->importRemoteProduct($payload);
        $this->log('webhook.product.updated', 'info', 'Mapped product refreshed from Salla', [
            'salla_product_id' => $sallaId, 'product_id' => $localId,
        ]);
    }

    // -----------------------------------------------------------------
    // Stats / reset / logging
    // -----------------------------------------------------------------

    public function stats(): array
    {
        $count = fn (string $type) => SallaMapping::where('entity_type', $type)->count();

        return [
            'products_total' => Product::whereNull('deleted_at')->count(),
            'products_mapped' => $count(SallaMapping::TYPE_PRODUCT),
            'variants_mapped' => $count(SallaMapping::TYPE_VARIANT),
            'customers_total' => Customer::whereNull('deleted_at')->count(),
            'customers_mapped' => $count(SallaMapping::TYPE_CUSTOMER),
            'orders_imported' => $count(SallaMapping::TYPE_ORDER),
        ];
    }

    public function resetMappings(?string $entityType = null): int
    {
        $query = SallaMapping::query();
        if ($entityType) {
            $query->where('entity_type', $entityType);
        } else {
            // Keep order mappings by default: they guard against duplicate sale imports
            $query->where('entity_type', '!=', SallaMapping::TYPE_ORDER);
        }

        return $query->delete();
    }

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            SallaLog::create([
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a sync
        }
    }

    /**
     * Salla money values appear as plain numbers, {amount, currency} objects,
     * or nested {amount: {amount, currency}} — flatten them all to a float.
     */
    public static function amt(mixed $value): float
    {
        while (is_array($value)) {
            $value = $value['amount'] ?? 0;
        }

        return (float) $value;
    }
}
