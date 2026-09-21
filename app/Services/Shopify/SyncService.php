<?php

namespace App\Services\Shopify;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\product_warehouse;
use App\Models\Sale;
use App\Models\ShopifyLink;
use App\Models\ShopifyLog;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncRun;
use App\Models\Unit;
use App\Models\User;
// App\Models\Client is imported above and would otherwise shadow this
// namespace's own Client, so the API client is aliased explicitly.
use App\Services\Shopify\Client as Api;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves data between the ERP and one Shopify shop.
 *
 * Shape of every sync: create a run row, walk the source in batches, and after
 * each batch write progress and check whether a cancel was requested. Nothing
 * is held in memory across the whole set, so a catalogue of 50,000 products
 * costs the same as one of 50.
 *
 * Two rules hold everywhere:
 *  - A record is matched by its link row first, then by SKU/email as a fallback.
 *    Matching on name is never done: two products called "Blue T-Shirt" are not
 *    the same product, and merging them silently corrupts both.
 *  - Pulls only create local records when the store is configured to allow it.
 *    Otherwise an unmatched remote record is skipped and reported, never guessed.
 */
class SyncService
{
    private ShopifyStore $store;

    private Api $client;

    private ?ShopifySyncRun $run = null;

    /** Cached fallback author for imported sales. */
    private ?int $importUserId = null;

    /** How many records are handled between progress writes. */
    private const BATCH = 50;

    public function __construct(ShopifyStore $store)
    {
        $this->store = $store;
        $this->client = new Api($store->shop_domain, $store->access_token, $store->api_version);
    }

    public static function for(ShopifyStore $store): self
    {
        return new self($store);
    }

    public function client(): Api
    {
        return $this->client;
    }

    // ------------------------------------------------------------ run shell --

    /**
     * Attach an existing run so the single-record entry points above report
     * their counters somewhere. Used by the webhook handler, which processes one
     * record at a time but should still show up in the sync history.
     */
    public function attachRun(ShopifySyncRun $run): void
    {
        $this->run = $run;
    }

    public function currentRun(): ?ShopifySyncRun
    {
        return $this->run;
    }

    /**
     * Start a run and dispatch it. Returns the finished run row.
     *
     * @param  array  $options  ['dry_run' => bool, 'only_unsynced' => bool, 'since' => 'Y-m-d']
     */
    public function run(string $entity, string $direction, array $options = [], ?int $userId = null): ShopifySyncRun
    {
        $this->run = ShopifySyncRun::create([
            'store_id' => $this->store->id,
            'user_id' => $userId,
            'entity' => $entity,
            'direction' => $direction,
            'status' => 'running',
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'stage' => 'starting',
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        try {
            $method = 'sync'.Str::studly($entity).Str::studly($direction);
            if (! method_exists($this, $method)) {
                throw new \RuntimeException("Cannot {$direction} {$entity} — not supported.");
            }

            $this->$method($options);

            $this->run->refresh();
            if (! $this->run->cancel_requested) {
                $this->finish('completed');
            } else {
                $this->finish('cancelled');
            }
        } catch (\Throwable $e) {
            $this->finish('failed', $e->getMessage());
            $this->log('error', $entity.'.'.$direction, 'Sync failed: '.$e->getMessage(), [
                'exception' => get_class($e),
                'line' => $e->getLine(),
            ], $entity);
        }

        $this->store->update(['last_sync_at' => now()]);

        return $this->run->fresh();
    }

    private function finish(string $status, ?string $error = null): void
    {
        if (! $this->run) {
            return;
        }

        $this->run->update([
            'status' => $status,
            'finished_at' => now(),
            'last_error' => $error,
            'stage' => $status === 'completed' ? 'done' : $this->run->stage,
            'percentage' => $status === 'completed' ? 100 : $this->run->percentage,
        ]);
    }

    /** Write progress and report whether the caller asked us to stop. */
    private function tick(string $stage = null, ?string $cursor = null): bool
    {
        if (! $this->run) {
            return false;
        }

        $total = (int) $this->run->total_items;
        $processed = (int) $this->run->processed_items;

        $this->run->percentage = $total > 0
            ? (int) min(99, floor($processed / $total * 100))
            : ($processed > 0 ? 50 : 0);

        if ($stage !== null) {
            $this->run->stage = $stage;
        }
        if ($cursor !== null) {
            $this->run->cursor = $cursor;
        }
        $this->run->heartbeat_at = now();
        $this->run->save();

        // Re-read only the cancel flag: another request sets it while we work.
        $cancelled = (bool) ShopifySyncRun::where('id', $this->run->id)->value('cancel_requested');
        if ($cancelled) {
            $this->run->cancel_requested = true;
        }

        return $cancelled;
    }

    private function bump(string $field, int $by = 1): void
    {
        if (! $this->run) {
            return;
        }
        $this->run->$field = (int) $this->run->$field + $by;
    }

    private function log(string $level, string $action, string $message, array $context = [], ?string $entity = null): void
    {
        ShopifyLog::write(
            $this->store->id,
            $action,
            $message,
            $level,
            $context,
            $this->run ? $this->run->id : null,
            $entity
        );
    }

    private function dryRun(): bool
    {
        return $this->run && $this->run->dry_run;
    }

    // ------------------------------------------------------------- products --

    /** ERP -> Shopify. */
    private function syncProductsPush(array $options): void
    {
        $query = Product::whereNull('deleted_at')->where('is_active', 1);

        if (! empty($options['only_unsynced'])) {
            $linked = ShopifyLink::where('store_id', $this->store->id)
                ->where('entity_type', 'product')
                ->pluck('local_id');
            $query->whereNotIn('id', $linked);
        }

        $this->run->update([
            'total_items' => (clone $query)->count(),
            'stage' => 'pushing products',
        ]);

        $cancelled = false;
        (clone $query)->orderBy('id')->chunkById(self::BATCH, function ($products) use (&$cancelled) {
            foreach ($products as $product) {
                try {
                    $this->pushProduct($product);
                } catch (\Throwable $e) {
                    $this->bump('failed_items');
                    $this->log('error', 'product.push', 'Product #'.$product->id.': '.$e->getMessage(),
                        ['product_id' => $product->id, 'sku' => $product->code], 'products');
                }
                $this->bump('processed_items');
            }

            if ($this->tick('pushing products')) {
                $cancelled = true;

                return false;   // stops chunking
            }

            return true;
        });
    }

    private function pushProduct(Product $product): void
    {
        $payload = $this->productPayload($product);
        $hash = $this->fingerprint($payload);
        $link = ShopifyLink::findLocal($this->store->id, 'product', $product->id);

        // Nothing changed since the last push — skip the API call entirely.
        if ($link && $link->push_hash === $hash) {
            $this->bump('skipped_items');

            return;
        }

        if ($this->dryRun()) {
            $this->bump($link ? 'updated_items' : 'created_items');

            return;
        }

        $res = $link
            ? $this->client->put('products/'.$link->shopify_id, ['product' => $payload])
            : $this->client->post('products', ['product' => $payload]);

        // A product deleted in Shopify leaves a stale link behind. Drop it and
        // let the next run recreate the product rather than failing for ever.
        if (! $res->successful() && $res->status() === 404 && $link) {
            $link->delete();
            $res = $this->client->post('products', ['product' => $payload]);
            $link = null;
        }

        if (! $res->successful()) {
            throw new \RuntimeException($this->client->explain($res));
        }

        $remote = $res->json()['product'] ?? [];
        if (empty($remote['id'])) {
            throw new \RuntimeException('Shopify accepted the product but returned no id.');
        }

        ShopifyLink::link($this->store->id, 'product', $product->id, $remote['id'], [
            'handle' => $remote['handle'] ?? null,
            'push_hash' => $hash,
        ]);

        $this->linkVariants($product, $remote);
        $this->bump($link ? 'updated_items' : 'created_items');
    }

    /**
     * Build the Shopify product body from an ERP product.
     *
     * Pure — no API calls, no writes — so the mapping can be exercised directly
     * in a test without a shop to talk to.
     */
    public function productPayload(Product $product): array
    {
        $priceField = $this->store->price_field ?: 'price';
        $category = $product->category_id ? Category::find($product->category_id) : null;
        $brand = $product->brand_id ? Brand::find($product->brand_id) : null;

        $payload = [
            'title' => $product->name,
            'body_html' => (string) ($product->note ?? ''),
            'vendor' => $brand->name ?? '',
            'product_type' => $category->name ?? '',
            'status' => $product->is_active ? 'active' : 'draft',
            // hide_from_online_store is the ERP's own "do not publish" switch;
            // honouring it is the difference between a catalogue sync and an
            // accidental product launch.
            'published' => ! $product->hide_from_online_store,
        ];

        if (! empty($product->tags)) {
            $payload['tags'] = is_array($product->tags) ? implode(', ', $product->tags) : (string) $product->tags;
        }

        $variants = ProductVariant::where('product_id', $product->id)->whereNull('deleted_at')->get();

        if ($product->is_variant && $variants->count()) {
            $payload['options'] = [['name' => 'Variant', 'values' => $variants->pluck('name')->values()->all()]];
            $payload['variants'] = $variants->map(fn ($v) => array_filter([
                'option1' => $v->name,
                'sku' => $v->code ?: null,
                'barcode' => $v->gtin ?: null,
                'price' => number_format((float) ($v->$priceField ?? $v->price), 2, '.', ''),
                'inventory_management' => 'shopify',
                'weight' => (float) ($product->weight ?: 0),
                'weight_unit' => 'kg',
            ], fn ($x) => $x !== null))->values()->all();
        } else {
            $payload['variants'] = [array_filter([
                'sku' => $product->code ?: null,
                'barcode' => $product->gtin ?: null,
                'price' => number_format((float) ($product->$priceField ?? $product->price), 2, '.', ''),
                'inventory_management' => 'shopify',
                'weight' => (float) ($product->weight ?: 0),
                'weight_unit' => 'kg',
            ], fn ($x) => $x !== null)];
        }

        return $payload;
    }

    /** Remember each Shopify variant id and its inventory_item_id. */
    private function linkVariants(Product $product, array $remote): void
    {
        $remoteVariants = $remote['variants'] ?? [];
        if (! is_array($remoteVariants) || ! count($remoteVariants)) {
            return;
        }

        $local = ProductVariant::where('product_id', $product->id)->whereNull('deleted_at')->get();

        foreach ($remoteVariants as $rv) {
            // Match on SKU where there is one; fall back to the variant name.
            $match = null;
            if (! empty($rv['sku'])) {
                $match = $local->firstWhere('code', $rv['sku']);
            }
            if (! $match && ! empty($rv['option1'])) {
                $match = $local->firstWhere('name', $rv['option1']);
            }

            if ($match) {
                ShopifyLink::link($this->store->id, 'variant', $match->id, $rv['id'], [
                    'secondary_id' => $rv['inventory_item_id'] ?? null,
                ]);
            } elseif (! $product->is_variant) {
                // Simple product: its single Shopify variant carries the
                // inventory item, so hang it off the product id.
                ShopifyLink::link($this->store->id, 'inventory_item', $product->id, $rv['id'], [
                    'secondary_id' => $rv['inventory_item_id'] ?? null,
                ]);
            }
        }
    }

    /** Shopify -> ERP. */
    private function syncProductsPull(array $options): void
    {
        $total = $this->client->count('products');
        $this->run->update(['total_items' => $total ?: 0, 'stage' => 'pulling products']);

        $cursor = null;
        do {
            $page = $this->client->page('products', 'products', ['status' => 'active'], $cursor);
            if (! $page['ok']) {
                throw new \RuntimeException($page['error'] ?: 'Could not read products from Shopify.');
            }

            foreach ($page['items'] as $remote) {
                try {
                    $this->pullProduct($remote);
                } catch (\Throwable $e) {
                    $this->bump('failed_items');
                    $this->log('error', 'product.pull', 'Shopify product '.($remote['id'] ?? '?').': '.$e->getMessage(),
                        ['shopify_id' => $remote['id'] ?? null], 'products');
                }
                $this->bump('processed_items');
            }

            $cursor = $page['next'];
            if ($this->tick('pulling products', $cursor)) {
                return;
            }
        } while ($cursor);
    }

    public function pullProduct(array $remote): void
    {
        $shopifyId = (string) ($remote['id'] ?? '');
        if ($shopifyId === '') {
            throw new \RuntimeException('Shopify product had no id.');
        }

        $firstVariant = $remote['variants'][0] ?? [];
        $sku = trim((string) ($firstVariant['sku'] ?? ''));

        // Link first, then SKU. Never by title.
        $product = null;
        $link = ShopifyLink::findRemote($this->store->id, 'product', $shopifyId);
        if ($link) {
            $product = Product::whereNull('deleted_at')->find($link->local_id);
        }
        if (! $product && $sku !== '') {
            $product = Product::whereNull('deleted_at')->where('code', $sku)->first();
        }

        if (! $product && ! $this->store->create_missing_products) {
            $this->bump('skipped_items');
            $this->log('warning', 'product.pull', 'Skipped "'.($remote['title'] ?? $shopifyId).'" — no matching SKU and creating products is off.',
                ['shopify_id' => $shopifyId, 'sku' => $sku], 'products');

            return;
        }

        if ($this->dryRun()) {
            $this->bump($product ? 'updated_items' : 'created_items');

            return;
        }

        $isNew = ! $product;

        DB::transaction(function () use (&$product, $remote, $firstVariant, $sku, $shopifyId, $isNew) {
            if (! $product) {
                $product = new Product;
                $product->code = $sku !== '' ? $sku : 'SHOP-'.$shopifyId;
                $product->type = 'standard';
                $product->tax_method = '1';
                $product->TaxNet = 0;
                $product->discount = 0;
                $product->cost = 0;
                $product->is_variant = 0;
                $product->is_active = 1;
                $product->unit_id = Unit::whereNull('deleted_at')->value('id');
                $product->unit_sale_id = $product->unit_id;
                $product->unit_purchase_id = $product->unit_id;
            }

            $product->name = $remote['title'] ?? $product->name;
            $product->note = $remote['body_html'] ?? $product->note;
            if (isset($firstVariant['price'])) {
                $product->price = (float) $firstVariant['price'];
            }
            if (! empty($firstVariant['barcode'])) {
                $product->gtin = $firstVariant['barcode'];
            }
            if (! empty($remote['product_type'])) {
                $product->category_id = $this->categoryIdFor($remote['product_type']) ?: $product->category_id;
            }
            // products.category_id is NOT NULL with a foreign key, so a Shopify
            // product carrying no product_type would fail the insert outright.
            if (! $product->category_id) {
                $product->category_id = $this->fallbackCategoryId();
            }
            if (! empty($remote['vendor'])) {
                $product->brand_id = $this->brandIdFor($remote['vendor']) ?: $product->brand_id;
            }
            $product->save();

            ShopifyLink::link($this->store->id, 'product', $product->id, $shopifyId, [
                'handle' => $remote['handle'] ?? null,
            ]);

            foreach ($remote['variants'] ?? [] as $rv) {
                if (! empty($rv['inventory_item_id'])) {
                    ShopifyLink::link($this->store->id, 'inventory_item', $product->id, $rv['id'], [
                        'secondary_id' => $rv['inventory_item_id'],
                    ]);
                    break;
                }
            }

            if ($isNew) {
                $this->ensureStockRows($product->id);
            }
        }, 3);

        $this->bump($isNew ? 'created_items' : 'updated_items');
    }

    private function categoryIdFor(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $existing = Category::whereNull('deleted_at')->where('name', $name)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) Category::create([
            'name' => $name,
            'code' => Str::upper(Str::slug(Str::limit($name, 20, ''))) ?: Str::random(6),
        ])->id;
    }

    /**
     * The category an untyped Shopify product lands in. Reuses an existing
     * "Uncategorised" if one is already there rather than making a second.
     */
    private function fallbackCategoryId(): int
    {
        $existing = Category::whereNull('deleted_at')->where('name', 'Uncategorised')->first();
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) Category::create(['name' => 'Uncategorised', 'code' => 'UNCAT'])->id;
    }

    private function brandIdFor(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $existing = Brand::whereNull('deleted_at')->where('name', $name)->first();

        return (int) ($existing ? $existing->id : Brand::create(['name' => $name])->id);
    }

    /** A product with no product_warehouse row can never hold stock. */
    private function ensureStockRows(int $productId): void
    {
        $warehouses = DB::table('warehouses')->whereNull('deleted_at')->pluck('id');
        foreach ($warehouses as $warehouseId) {
            $exists = product_warehouse::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->whereNull('product_variant_id')
                ->whereNull('deleted_at')
                ->exists();

            if (! $exists) {
                product_warehouse::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => null,
                    'qte' => 0,
                    'manage_stock' => 1,
                ]);
            }
        }
    }

    // ------------------------------------------------------------ inventory --

    /** ERP stock levels -> Shopify. */
    private function syncInventoryPush(array $options): void
    {
        $locationId = $this->store->location_id;
        if (! $locationId) {
            throw new \RuntimeException('This store has no Shopify location set — inventory cannot be pushed without one.');
        }

        $warehouseId = $this->store->warehouse_id;
        if (! $warehouseId) {
            throw new \RuntimeException('This store has no ERP warehouse set — there is no stock figure to send.');
        }

        $links = ShopifyLink::where('store_id', $this->store->id)
            ->whereIn('entity_type', ['inventory_item', 'variant'])
            ->whereNotNull('secondary_id');

        $this->run->update(['total_items' => (clone $links)->count(), 'stage' => 'pushing inventory']);

        (clone $links)->orderBy('id')->chunkById(self::BATCH, function ($rows) use ($locationId, $warehouseId) {
            foreach ($rows as $link) {
                try {
                    $qty = $this->localStock($link, $warehouseId);
                    if ($qty === null) {
                        $this->bump('skipped_items');
                        $this->bump('processed_items');

                        continue;
                    }

                    if (! $this->dryRun()) {
                        $res = $this->client->post('inventory_levels/set', [
                            'location_id' => $locationId,
                            'inventory_item_id' => $link->secondary_id,
                            'available' => $qty,
                        ]);
                        if (! $res->successful()) {
                            throw new \RuntimeException($this->client->explain($res));
                        }
                    }
                    $this->bump('updated_items');
                } catch (\Throwable $e) {
                    $this->bump('failed_items');
                    $this->log('error', 'inventory.push', 'Inventory item '.$link->secondary_id.': '.$e->getMessage(),
                        ['link_id' => $link->id], 'inventory');
                }
                $this->bump('processed_items');
            }

            return ! $this->tick('pushing inventory');
        });
    }

    /** Stock on hand for whatever this link points at, or null if untracked. */
    private function localStock(ShopifyLink $link, int $warehouseId): ?int
    {
        $query = product_warehouse::whereNull('deleted_at')->where('warehouse_id', $warehouseId);

        if ($link->entity_type === 'variant') {
            $query->where('product_variant_id', $link->local_id);
        } else {
            $query->where('product_id', $link->local_id)->whereNull('product_variant_id');
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        return (int) round((float) $row->qte);
    }

    /** Shopify stock levels -> ERP. */
    private function syncInventoryPull(array $options): void
    {
        $locationId = $this->store->location_id;
        $warehouseId = $this->store->warehouse_id;
        if (! $locationId || ! $warehouseId) {
            throw new \RuntimeException('This store needs both a Shopify location and an ERP warehouse before inventory can be pulled.');
        }

        $links = ShopifyLink::where('store_id', $this->store->id)
            ->whereIn('entity_type', ['inventory_item', 'variant'])
            ->whereNotNull('secondary_id')
            ->get()
            ->keyBy('secondary_id');

        $this->run->update(['total_items' => $links->count(), 'stage' => 'pulling inventory']);

        // Shopify caps inventory_levels to 50 ids per call.
        foreach ($links->keys()->chunk(50) as $chunk) {
            $res = $this->client->get('inventory_levels', [
                'location_ids' => $locationId,
                'inventory_item_ids' => $chunk->implode(','),
                'limit' => 250,
            ]);

            if (! $res->successful()) {
                throw new \RuntimeException($this->client->explain($res));
            }

            foreach ($res->json()['inventory_levels'] ?? [] as $level) {
                $link = $links->get((string) ($level['inventory_item_id'] ?? ''));
                $this->bump('processed_items');

                if (! $link) {
                    $this->bump('skipped_items');

                    continue;
                }

                try {
                    if (! $this->dryRun()) {
                        $this->writeLocalStock($link, (int) $warehouseId, (float) ($level['available'] ?? 0));
                    }
                    $this->bump('updated_items');
                } catch (\Throwable $e) {
                    $this->bump('failed_items');
                    $this->log('error', 'inventory.pull', 'Inventory item '.$link->secondary_id.': '.$e->getMessage(),
                        ['link_id' => $link->id], 'inventory');
                }
            }

            if ($this->tick('pulling inventory')) {
                return;
            }
        }
    }

    public function writeLocalStock(ShopifyLink $link, int $warehouseId, float $qty): void
    {
        $query = product_warehouse::whereNull('deleted_at')->where('warehouse_id', $warehouseId);

        if ($link->entity_type === 'variant') {
            $variant = ProductVariant::find($link->local_id);
            if (! $variant) {
                return;
            }
            $query->where('product_id', $variant->product_id)->where('product_variant_id', $variant->id);
        } else {
            $query->where('product_id', $link->local_id)->whereNull('product_variant_id');
        }

        $row = $query->first();
        if ($row) {
            $row->qte = max(0, $qty);
            $row->save();
        }
    }

    // ------------------------------------------------------------ customers --

    private function syncCustomersPush(array $options): void
    {
        $query = Client::whereNull('deleted_at');
        if (! empty($options['only_unsynced'])) {
            $linked = ShopifyLink::where('store_id', $this->store->id)
                ->where('entity_type', 'customer')->pluck('local_id');
            $query->whereNotIn('id', $linked);
        }

        $this->run->update(['total_items' => (clone $query)->count(), 'stage' => 'pushing customers']);

        (clone $query)->orderBy('id')->chunkById(self::BATCH, function ($clients) {
            foreach ($clients as $client) {
                try {
                    $this->pushCustomer($client);
                } catch (\Throwable $e) {
                    $this->bump('failed_items');
                    $this->log('error', 'customer.push', 'Customer #'.$client->id.': '.$e->getMessage(),
                        ['client_id' => $client->id, 'email' => $client->email], 'customers');
                }
                $this->bump('processed_items');
            }

            return ! $this->tick('pushing customers');
        });
    }

    private function pushCustomer(Client $client): void
    {
        // Shopify keys customers on email; without one there is nothing stable
        // to match on and a push would create a duplicate on every run.
        if (empty($client->email)) {
            $this->bump('skipped_items');

            return;
        }

        $payload = $this->customerPayload($client);
        $hash = $this->fingerprint($payload);
        $link = ShopifyLink::findLocal($this->store->id, 'customer', $client->id);

        if ($link && $link->push_hash === $hash) {
            $this->bump('skipped_items');

            return;
        }

        if ($this->dryRun()) {
            $this->bump($link ? 'updated_items' : 'created_items');

            return;
        }

        $res = $link
            ? $this->client->put('customers/'.$link->shopify_id, ['customer' => $payload])
            : $this->client->post('customers', ['customer' => $payload]);

        // Shopify rejects a duplicate email with 422. That means the customer is
        // already there and simply not linked — find them and link, rather than
        // reporting a failure the user cannot act on.
        if (! $res->successful() && $res->status() === 422 && ! $link) {
            $found = $this->findRemoteCustomerByEmail($client->email);
            if ($found) {
                ShopifyLink::link($this->store->id, 'customer', $client->id, $found['id'], ['push_hash' => $hash]);
                $this->bump('updated_items');

                return;
            }
        }

        if (! $res->successful()) {
            throw new \RuntimeException($this->client->explain($res));
        }

        $remote = $res->json()['customer'] ?? [];
        if (empty($remote['id'])) {
            throw new \RuntimeException('Shopify accepted the customer but returned no id.');
        }

        ShopifyLink::link($this->store->id, 'customer', $client->id, $remote['id'], ['push_hash' => $hash]);
        $this->bump($link ? 'updated_items' : 'created_items');
    }

    /** Pure mapping: ERP client -> Shopify customer body. */
    public function customerPayload(Client $client): array
    {
        [$first, $last] = $this->splitName($client);

        $payload = [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $client->email,
            'phone' => $client->phone ?: null,
            'tags' => 'stocky-erp',
        ];

        if ($client->adresse || $client->city || $client->country) {
            $payload['addresses'] = [array_filter([
                'address1' => $client->adresse ?: null,
                'city' => $client->city ?: null,
                'province' => $client->state ?: null,
                'zip' => $client->zip ?: null,
                'country' => $client->country ?: null,
                'phone' => $client->phone ?: null,
                'first_name' => $first,
                'last_name' => $last,
            ], fn ($v) => $v !== null)];
        }

        return array_filter($payload, fn ($v) => $v !== null);
    }

    private function splitName(Client $client): array
    {
        if ($client->firstname || $client->lastname) {
            return [(string) $client->firstname, (string) $client->lastname];
        }

        $parts = preg_split('/\s+/', trim((string) $client->name), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function findRemoteCustomerByEmail(string $email): ?array
    {
        $res = $this->client->get('customers/search', ['query' => 'email:'.$email, 'limit' => 1]);
        if (! $res->successful()) {
            return null;
        }

        return $res->json()['customers'][0] ?? null;
    }

    private function syncCustomersPull(array $options): void
    {
        $total = $this->client->count('customers');
        $this->run->update(['total_items' => $total ?: 0, 'stage' => 'pulling customers']);

        $cursor = null;
        do {
            $page = $this->client->page('customers', 'customers', [], $cursor);
            if (! $page['ok']) {
                throw new \RuntimeException($page['error'] ?: 'Could not read customers from Shopify.');
            }

            foreach ($page['items'] as $remote) {
                try {
                    $this->pullCustomer($remote);
                } catch (\Throwable $e) {
                    $this->bump('failed_items');
                    $this->log('error', 'customer.pull', 'Shopify customer '.($remote['id'] ?? '?').': '.$e->getMessage(),
                        ['shopify_id' => $remote['id'] ?? null], 'customers');
                }
                $this->bump('processed_items');
            }

            $cursor = $page['next'];
            if ($this->tick('pulling customers', $cursor)) {
                return;
            }
        } while ($cursor);
    }

    public function pullCustomer(array $remote): int
    {
        $shopifyId = (string) ($remote['id'] ?? '');
        if ($shopifyId === '') {
            throw new \RuntimeException('Shopify customer had no id.');
        }

        $email = trim((string) ($remote['email'] ?? ''));

        $client = null;
        $link = ShopifyLink::findRemote($this->store->id, 'customer', $shopifyId);
        if ($link) {
            $client = Client::whereNull('deleted_at')->find($link->local_id);
        }
        if (! $client && $email !== '') {
            $client = Client::whereNull('deleted_at')->where('email', $email)->first();
        }

        if (! $client && ! $this->store->create_missing_customers) {
            $this->bump('skipped_items');

            return 0;
        }

        if ($this->dryRun()) {
            $this->bump($client ? 'updated_items' : 'created_items');

            return $client ? (int) $client->id : 0;
        }

        $isNew = ! $client;
        $address = $remote['default_address'] ?? [];
        $name = trim(($remote['first_name'] ?? '').' '.($remote['last_name'] ?? ''));
        if ($name === '') {
            $name = $email !== '' ? $email : 'Shopify customer '.$shopifyId;
        }

        if (! $client) {
            $client = new Client;
            $client->code = Str::upper(Str::random(8));
        }

        $client->name = $name;
        $client->firstname = $remote['first_name'] ?? $client->firstname;
        $client->lastname = $remote['last_name'] ?? $client->lastname;
        if ($email !== '') {
            $client->email = $email;
        }
        $client->phone = $remote['phone'] ?? ($address['phone'] ?? $client->phone);
        $client->adresse = $address['address1'] ?? $client->adresse;
        $client->city = $address['city'] ?? $client->city;
        $client->state = $address['province'] ?? $client->state;
        $client->zip = $address['zip'] ?? $client->zip;
        $client->country = $address['country'] ?? $client->country;
        $client->save();

        ShopifyLink::link($this->store->id, 'customer', $client->id, $shopifyId);
        $this->bump($isNew ? 'created_items' : 'updated_items');

        return (int) $client->id;
    }

    // --------------------------------------------------------------- orders --

    /**
     * Shopify -> ERP. Orders are pull-only: the shop is where they are placed,
     * so pushing an ERP sale back would invent an order the customer never made.
     */
    private function syncOrdersPull(array $options): void
    {
        $query = ['status' => 'any'];
        if (! empty($options['since'])) {
            $query['created_at_min'] = $options['since'].'T00:00:00Z';
        }

        $total = $this->client->count('orders', $query);
        $this->run->update(['total_items' => $total ?: 0, 'stage' => 'pulling orders']);

        $cursor = null;
        do {
            $page = $this->client->page('orders', 'orders', $query, $cursor);
            if (! $page['ok']) {
                throw new \RuntimeException($page['error'] ?: 'Could not read orders from Shopify.');
            }

            foreach ($page['items'] as $remote) {
                try {
                    $this->pullOrder($remote);
                } catch (\Throwable $e) {
                    $this->bump('failed_items');
                    $this->log('error', 'order.pull', 'Shopify order '.($remote['name'] ?? $remote['id'] ?? '?').': '.$e->getMessage(),
                        ['shopify_id' => $remote['id'] ?? null], 'orders');
                }
                $this->bump('processed_items');
            }

            $cursor = $page['next'];
            if ($this->tick('pulling orders', $cursor)) {
                return;
            }
        } while ($cursor);
    }

    public function pullOrder(array $remote): void
    {
        $shopifyId = (string) ($remote['id'] ?? '');
        if ($shopifyId === '') {
            throw new \RuntimeException('Shopify order had no id.');
        }

        // Already imported: the link row is what makes re-running safe, and what
        // makes a webhook that fires three times import one sale.
        if (ShopifyLink::findRemote($this->store->id, 'order', $shopifyId)) {
            $this->bump('skipped_items');

            return;
        }

        $warehouseId = $this->store->warehouse_id;
        if (! $warehouseId) {
            throw new \RuntimeException('This store has no ERP warehouse set — an order cannot be filed without one.');
        }

        if ($this->dryRun()) {
            $this->bump('created_items');

            return;
        }

        $clientId = $this->resolveOrderCustomer($remote);
        if (! $clientId) {
            $this->bump('skipped_items');
            $this->log('warning', 'order.pull', 'Order '.($remote['name'] ?? $shopifyId).' skipped — no customer could be matched or created.',
                ['shopify_id' => $shopifyId], 'orders');

            return;
        }

        $status = $this->mapOrderStatus($remote);
        $lines = $this->buildOrderLines($remote);

        if (! count($lines['details'])) {
            $this->bump('skipped_items');
            $this->log('warning', 'order.pull', 'Order '.($remote['name'] ?? $shopifyId).' skipped — none of its line items match a product in the ERP.',
                ['shopify_id' => $shopifyId], 'orders');

            return;
        }

        DB::transaction(function () use ($remote, $shopifyId, $clientId, $warehouseId, $status, $lines) {
            $placedAt = ! empty($remote['created_at']) ? \Carbon\Carbon::parse($remote['created_at']) : now();
            $paid = (float) ($remote['total_price'] ?? 0) - (float) ($remote['total_outstanding'] ?? 0);

            $sale = Sale::create([
                'sale_uuid' => (string) Str::uuid(),
                'date' => $placedAt->toDateString(),
                'time' => $placedAt->format('H:i:s'),
                'Ref' => 'SHOPIFY-'.$this->store->id.'-'.($remote['name'] ?? $shopifyId),
                'is_pos' => 0,
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'user_id' => $this->importUserId(),
                'tax_rate' => 0,
                'TaxNet' => (float) ($remote['total_tax'] ?? 0),
                'discount' => (float) ($remote['total_discounts'] ?? 0),
                'shipping' => $lines['shipping'],
                'GrandTotal' => (float) ($remote['total_price'] ?? $lines['subtotal']),
                'paid_amount' => max(0, $paid),
                'payment_statut' => $status['payment'],
                'statut' => $status['sale'],
                'shipping_status' => $status['shipping'],
                'notes' => trim('Imported from Shopify order '.($remote['name'] ?? '#'.$shopifyId).' — '.($remote['note'] ?? '')),
            ]);

            foreach ($lines['details'] as $detail) {
                $detail['sale_id'] = $sale->id;
                $detail['date'] = $placedAt->toDateString();
                $detail['created_at'] = now();
                $detail['updated_at'] = now();
                DB::table('sale_details')->insert($detail);
            }

            ShopifyLink::link($this->store->id, 'order', $sale->id, $shopifyId, [
                'handle' => $remote['name'] ?? null,
            ]);

            // Same rule the WooCommerce importer follows: stock only moves for
            // orders that actually completed. Deducting on a pending order would
            // double-count when it later completes.
            if ($status['sale'] === 'completed') {
                $this->applyStock($lines['stock'], (int) $warehouseId);
            }
        }, 3);

        $this->bump('created_items');
    }

    /**
     * Who an imported sale is recorded against.
     *
     * sales.user_id is NOT NULL, and a webhook-driven import has no logged-in
     * user at all — so falling back to an administrator is what keeps an order
     * arriving at 3am from failing outright. Cached per instance because an
     * order sync calls this once per order.
     */
    private function importUserId(): ?int
    {
        if ($this->run && $this->run->user_id) {
            return (int) $this->run->user_id;
        }

        if ($this->importUserId !== null) {
            return $this->importUserId;
        }

        $admin = User::whereNull('deleted_at')
            ->whereHas('roles', fn ($q) => $q->where('roles.id', 1))
            ->value('id');

        $this->importUserId = (int) ($admin ?: User::whereNull('deleted_at')->value('id'));

        return $this->importUserId ?: null;
    }

    /** Link -> email -> create, in that order. */
    private function resolveOrderCustomer(array $remote): ?int
    {
        $customer = $remote['customer'] ?? null;

        if (is_array($customer) && ! empty($customer['id'])) {
            $existing = ShopifyLink::localIdFor($this->store->id, 'customer', $customer['id']);
            if ($existing && Client::whereNull('deleted_at')->whereKey($existing)->exists()) {
                return $existing;
            }
        }

        $email = trim((string) ($customer['email'] ?? $remote['email'] ?? ''));
        if ($email !== '') {
            $local = Client::whereNull('deleted_at')->where('email', $email)->first();
            if ($local) {
                if (is_array($customer) && ! empty($customer['id'])) {
                    ShopifyLink::link($this->store->id, 'customer', $local->id, $customer['id']);
                }

                return (int) $local->id;
            }
        }

        if (is_array($customer) && ! empty($customer['id']) && $this->store->create_missing_customers) {
            $created = $this->pullCustomer($customer);

            return $created ?: null;
        }

        return null;
    }

    /**
     * Turn Shopify line items into sale_details rows.
     *
     * A line whose product is not in the ERP is dropped and reported rather than
     * invented — a sale row with a made-up product id corrupts every stock and
     * margin report downstream.
     */
    private function buildOrderLines(array $remote): array
    {
        $details = [];
        $stock = [];
        $subtotal = 0.0;

        foreach ($remote['line_items'] ?? [] as $line) {
            $productId = null;
            $variantId = null;

            if (! empty($line['product_id'])) {
                $productId = ShopifyLink::localIdFor($this->store->id, 'product', $line['product_id']);
            }
            if (! $productId && ! empty($line['sku'])) {
                $productId = Product::whereNull('deleted_at')->where('code', $line['sku'])->value('id');
            }
            if (! $productId) {
                continue;
            }

            if (! empty($line['variant_id'])) {
                $variantId = ShopifyLink::localIdFor($this->store->id, 'variant', $line['variant_id']);
            }

            $qty = (float) ($line['quantity'] ?? 0);
            $price = (float) ($line['price'] ?? 0);
            $discount = 0.0;
            foreach ($line['discount_allocations'] ?? [] as $alloc) {
                $discount += (float) ($alloc['amount'] ?? 0);
            }

            $total = round($qty * $price - $discount, 2);
            $subtotal += $total;

            $product = Product::find($productId);

            $details[] = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'price' => $price,
                'sale_unit_id' => $product ? $product->unit_sale_id : null,
                'TaxNet' => (float) ($line['tax_lines'][0]['price'] ?? 0),
                'tax_method' => '1',
                'discount' => $discount,
                'discount_method' => '2',
                'total' => $total,
                'quantity' => $qty,
            ];

            $stock[] = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'qty' => $qty,
                'unit_id' => $product ? $product->unit_sale_id : null,
            ];
        }

        $shipping = 0.0;
        foreach ($remote['shipping_lines'] ?? [] as $ship) {
            $shipping += (float) ($ship['price'] ?? 0);
        }

        return ['details' => $details, 'stock' => $stock, 'subtotal' => $subtotal, 'shipping' => $shipping];
    }

    /** Apply the unit operator the same way the rest of the ERP does. */
    private function applyStock(array $adjustments, int $warehouseId): void
    {
        foreach ($adjustments as $adj) {
            $query = product_warehouse::whereNull('deleted_at')
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $adj['product_id']);

            if (! empty($adj['product_variant_id'])) {
                $query->where('product_variant_id', $adj['product_variant_id']);
            } else {
                $query->whereNull('product_variant_id');
            }

            $row = $query->first();
            if (! $row) {
                continue;
            }

            $unit = $adj['unit_id'] ? Unit::find($adj['unit_id']) : null;
            $qty = (float) $adj['qty'];

            if ($unit && (float) $unit->operator_value > 0) {
                $qty = $unit->operator === '/'
                    ? $qty / (float) $unit->operator_value
                    : $qty * (float) $unit->operator_value;
            }

            $row->qte = (float) $row->qte - $qty;
            $row->save();
        }
    }

    /** Map Shopify's two independent status fields onto the ERP's three. */
    public function mapOrderStatus(array $remote): array
    {
        $financial = strtolower((string) ($remote['financial_status'] ?? ''));
        $fulfilment = strtolower((string) ($remote['fulfillment_status'] ?? ''));
        $cancelled = ! empty($remote['cancelled_at']);

        $payment = 'unpaid';
        if (in_array($financial, ['paid', 'partially_refunded'], true)) {
            $payment = 'paid';
        } elseif (in_array($financial, ['partially_paid', 'authorized'], true)) {
            $payment = 'partial';
        }

        if ($cancelled) {
            $sale = 'pending';
        } elseif ($fulfilment === 'fulfilled') {
            $sale = 'completed';
        } else {
            $sale = 'pending';
        }

        $shipping = 'ordered';
        if ($fulfilment === 'fulfilled') {
            $shipping = 'delivered';
        } elseif ($fulfilment === 'partial') {
            $shipping = 'packed';
        }

        return ['payment' => $payment, 'sale' => $sale, 'shipping' => $shipping, 'cancelled' => $cancelled];
    }

    // ---------------------------------------------------------- collections --

    private function syncCollectionsPush(array $options): void
    {
        $query = Category::whereNull('deleted_at');
        $this->run->update(['total_items' => (clone $query)->count(), 'stage' => 'pushing collections']);

        (clone $query)->orderBy('id')->chunkById(self::BATCH, function ($categories) {
            foreach ($categories as $category) {
                try {
                    $payload = ['title' => $category->name];
                    $hash = $this->fingerprint($payload);
                    $link = ShopifyLink::findLocal($this->store->id, 'collection', $category->id);

                    if ($link && $link->push_hash === $hash) {
                        $this->bump('skipped_items');
                        $this->bump('processed_items');

                        continue;
                    }

                    if (! $this->dryRun()) {
                        $res = $link
                            ? $this->client->put('custom_collections/'.$link->shopify_id, ['custom_collection' => $payload])
                            : $this->client->post('custom_collections', ['custom_collection' => $payload]);

                        if (! $res->successful()) {
                            throw new \RuntimeException($this->client->explain($res));
                        }

                        $remote = $res->json()['custom_collection'] ?? [];
                        if (! empty($remote['id'])) {
                            ShopifyLink::link($this->store->id, 'collection', $category->id, $remote['id'], [
                                'handle' => $remote['handle'] ?? null,
                                'push_hash' => $hash,
                            ]);
                        }
                    }

                    $this->bump($link ? 'updated_items' : 'created_items');
                } catch (\Throwable $e) {
                    $this->bump('failed_items');
                    $this->log('error', 'collection.push', 'Category #'.$category->id.': '.$e->getMessage(),
                        ['category_id' => $category->id], 'collections');
                }
                $this->bump('processed_items');
            }

            return ! $this->tick('pushing collections');
        });
    }

    private function syncCollectionsPull(array $options): void
    {
        $this->run->update(['stage' => 'pulling collections']);

        // Custom collections are hand-built; smart ones are rule-driven. Both
        // read as a category here.
        foreach (['custom_collections' => 'custom_collections', 'smart_collections' => 'smart_collections'] as $endpoint => $key) {
            $cursor = null;
            do {
                $page = $this->client->page($endpoint, $key, [], $cursor);
                if (! $page['ok']) {
                    throw new \RuntimeException($page['error'] ?: 'Could not read collections from Shopify.');
                }

                foreach ($page['items'] as $remote) {
                    try {
                        $shopifyId = (string) ($remote['id'] ?? '');
                        $title = trim((string) ($remote['title'] ?? ''));
                        if ($shopifyId === '' || $title === '') {
                            $this->bump('skipped_items');
                            $this->bump('processed_items');

                            continue;
                        }

                        $link = ShopifyLink::findRemote($this->store->id, 'collection', $shopifyId);
                        $category = $link ? Category::whereNull('deleted_at')->find($link->local_id) : null;
                        if (! $category) {
                            $category = Category::whereNull('deleted_at')->where('name', $title)->first();
                        }

                        if (! $this->dryRun()) {
                            if (! $category) {
                                $category = Category::create([
                                    'name' => $title,
                                    'code' => Str::upper(Str::slug(Str::limit($title, 20, ''))) ?: Str::random(6),
                                ]);
                                $this->bump('created_items');
                            } else {
                                $category->name = $title;
                                $category->save();
                                $this->bump('updated_items');
                            }

                            ShopifyLink::link($this->store->id, 'collection', $category->id, $shopifyId, [
                                'handle' => $remote['handle'] ?? null,
                            ]);
                        } else {
                            $this->bump($category ? 'updated_items' : 'created_items');
                        }
                    } catch (\Throwable $e) {
                        $this->bump('failed_items');
                        $this->log('error', 'collection.pull', 'Shopify collection '.($remote['id'] ?? '?').': '.$e->getMessage(),
                            [], 'collections');
                    }
                    $this->bump('processed_items');
                }

                $cursor = $page['next'];
                if ($this->tick('pulling collections', $cursor)) {
                    return;
                }
            } while ($cursor);
        }
    }

    // -------------------------------------------------------- fulfillments --

    /**
     * ERP -> Shopify: tell the shop that an imported order has shipped.
     *
     * Only orders this store imported are considered, and only ones the ERP now
     * marks delivered. Shopify is told once; the link row stops it being told
     * again on the next run.
     */
    private function syncFulfillmentsPush(array $options): void
    {
        $orderLinks = ShopifyLink::where('store_id', $this->store->id)
            ->where('entity_type', 'order')
            ->get();

        $shipped = Sale::whereNull('deleted_at')
            ->whereIn('id', $orderLinks->pluck('local_id'))
            ->where('shipping_status', 'delivered')
            ->pluck('id');

        $pending = $orderLinks->filter(fn ($l) => $shipped->contains((int) $l->local_id) && ! $l->secondary_id);

        $this->run->update(['total_items' => $pending->count(), 'stage' => 'pushing fulfillments']);

        foreach ($pending as $link) {
            try {
                if (! $this->dryRun()) {
                    $this->fulfilRemoteOrder($link);
                }
                $this->bump('created_items');
            } catch (\Throwable $e) {
                $this->bump('failed_items');
                $this->log('error', 'fulfillment.push', 'Order '.$link->shopify_id.': '.$e->getMessage(),
                    ['sale_id' => $link->local_id], 'fulfillments');
            }

            $this->bump('processed_items');
            if ($this->tick('pushing fulfillments')) {
                return;
            }
        }
    }

    private function fulfilRemoteOrder(ShopifyLink $link): void
    {
        // Modern Shopify fulfils against fulfillment orders, not the order id.
        $res = $this->client->get('orders/'.$link->shopify_id.'/fulfillment_orders');
        if (! $res->successful()) {
            throw new \RuntimeException($this->client->explain($res));
        }

        $fulfillmentOrders = $res->json()['fulfillment_orders'] ?? [];
        $open = array_values(array_filter(
            $fulfillmentOrders,
            fn ($fo) => in_array(($fo['status'] ?? ''), ['open', 'in_progress'], true)
        ));

        if (! count($open)) {
            throw new \RuntimeException('Shopify reports nothing left to fulfil on this order.');
        }

        $created = $this->client->post('fulfillments', [
            'fulfillment' => [
                'line_items_by_fulfillment_order' => array_map(
                    fn ($fo) => ['fulfillment_order_id' => $fo['id']],
                    $open
                ),
                'notify_customer' => false,
            ],
        ]);

        if (! $created->successful()) {
            throw new \RuntimeException($this->client->explain($created));
        }

        $fulfillment = $created->json()['fulfillment'] ?? [];
        if (! empty($fulfillment['id'])) {
            $link->secondary_id = (string) $fulfillment['id'];
            $link->last_synced_at = now();
            $link->save();
        }
    }

    /** Shopify -> ERP: refresh shipping status on orders we imported. */
    private function syncFulfillmentsPull(array $options): void
    {
        $links = ShopifyLink::where('store_id', $this->store->id)
            ->where('entity_type', 'order')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        $this->run->update(['total_items' => $links->count(), 'stage' => 'pulling fulfillment status']);

        foreach ($links as $link) {
            try {
                $res = $this->client->get('orders/'.$link->shopify_id, [
                    'fields' => 'id,fulfillment_status,financial_status,cancelled_at',
                ]);

                if ($res->status() === 404) {
                    $this->bump('skipped_items');
                    $this->bump('processed_items');

                    continue;
                }
                if (! $res->successful()) {
                    throw new \RuntimeException($this->client->explain($res));
                }

                $status = $this->mapOrderStatus($res->json()['order'] ?? []);
                $sale = Sale::whereNull('deleted_at')->find($link->local_id);

                if ($sale && ! $this->dryRun() && $sale->shipping_status !== $status['shipping']) {
                    $sale->shipping_status = $status['shipping'];
                    $sale->payment_statut = $status['payment'];
                    $sale->save();
                    $this->bump('updated_items');
                } else {
                    $this->bump('skipped_items');
                }
            } catch (\Throwable $e) {
                $this->bump('failed_items');
                $this->log('error', 'fulfillment.pull', 'Order '.$link->shopify_id.': '.$e->getMessage(),
                    ['sale_id' => $link->local_id], 'fulfillments');
            }

            $this->bump('processed_items');
            if ($this->tick('pulling fulfillment status')) {
                return;
            }
        }
    }

    // --------------------------------------------------------------- shared --

    /** Stable fingerprint of an outbound payload, for skip-if-unchanged. */
    private function fingerprint(array $payload): string
    {
        ksort($payload);

        return substr(hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)), 0, 64);
    }
}
