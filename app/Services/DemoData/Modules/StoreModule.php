<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\Modules\Concerns\DemoRefs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Online store content: banners and popups (company-scoped), collections,
 * CMS pages, coupons, a flash sale, and a few online orders.
 *
 * Orders are only 'pending' / 'cancelled': a 'confirmed' order implies a
 * booked sale and stock movement, which demo data must not fake. Store
 * settings (store_settings / homepage lineup) are left untouched.
 */
class StoreModule extends DemoModule
{
    use DemoRefs;

    public function key(): string
    {
        return 'Store';
    }

    public function label(): string
    {
        return 'Online Store';
    }

    public function dataTables(): array
    {
        return ['store_banners', 'store_popups', 'collections', 'store_pages', 'store_coupons', 'online_orders'];
    }

    public function requiredTables(): array
    {
        return ['store_banners', 'collections', 'store_pages', 'store_coupons', 'online_orders', 'online_order_items'];
    }

    public function generate(DemoContext $ctx): array
    {
        $out = [];
        $products = $this->productIds($ctx, 8);
        $clients = $this->clientIds($ctx, 6);

        // Banners
        $n = 0;
        foreach ([['New Season Arrivals', 'top_left'], ['Free Shipping Over $50', 'top_right'], ['Best Sellers', 'center_left'], ['Join Our Newsletter', 'footer_left']] as [$title, $pos]) {
            $ctx->insert('store_banners', [
                'title' => $title, 'position' => $pos, 'link' => '/shop',
                'image' => 'images/brands/no-image.png', 'active' => 1,
            ]);
            $n++;
        }
        $out['Banners'] = $n;

        // Popups
        if ($ctx->columns('store_popups')) {
            $ctx->insert('store_popups', [
                'title' => 'Get 10% off your first order', 'message' => 'Subscribe to our newsletter and receive a welcome code.',
                'type' => 'subscription', 'cta_label' => 'Subscribe', 'cta_url' => '/newsletter',
                'enabled' => 1, 'trigger' => 'delay', 'delay_seconds' => 5, 'frequency' => 'once', 'sort_order' => 1,
            ]);
            $ctx->insert('store_popups', [
                'title' => 'Weekend Flash Sale', 'message' => 'Up to 30% off selected items this weekend only.',
                'type' => 'sale', 'cta_label' => 'Shop now', 'cta_url' => '/shop',
                'enabled' => 0, 'trigger' => 'exit', 'delay_seconds' => 0, 'frequency' => 'session', 'sort_order' => 2,
                'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5),
            ]);
            $out['Popups'] = 2;
        }

        // Collections
        $cols = $links = 0;
        foreach (['Featured Picks', 'New Arrivals', 'Gift Ideas'] as $i => $title) {
            $colId = $ctx->insert('collections', [
                'title' => $title,
                'slug' => $this->uniqueValue('collections', 'slug', Str::slug($title).'-'.$ctx->companyId),
                'description' => "Hand-picked selection: $title.",
                'limit' => 8,
                'sort_order' => $i,
            ]);
            $cols++;
            foreach (array_slice($products, $i, 4) ?: $products as $sort => $pid) {
                $ctx->insert('collection_product', [
                    'collection_id' => $colId, 'product_id' => $pid, 'sort_order' => $sort, 'pinned' => $sort === 0 ? 1 : 0,
                ]);
                $links++;
            }
        }
        $out['Collections'] = $cols;
        $out['Collection products'] = $links;

        // CMS pages
        $pages = [
            ['About Us', '<h2>Our story</h2><p>We started as a small neighbourhood shop and grew into a trusted online store.</p>'],
            ['Shipping & Returns', '<p>Orders ship within 2 business days. Returns are accepted within 14 days of delivery.</p>'],
            ['Privacy Policy', '<p>We only collect the information needed to process your orders and never sell it.</p>'],
            ['FAQ', '<h3>How do I track my order?</h3><p>Sign in and open "My orders" to see its status.</p>'],
        ];
        foreach ($pages as [$title, $html]) {
            $ctx->insert('store_pages', [
                'title' => $title,
                'slug' => $this->uniqueValue('store_pages', 'slug', Str::slug($title).'-'.$ctx->companyId),
                'content' => $html,
                'seo_title' => $title,
                'seo_description' => strip_tags($html),
                'published' => 1,
            ]);
        }
        $out['Pages'] = count($pages);

        // Coupons
        $coupons = [];
        foreach ([
            ['WELCOME10', 'Welcome discount for new customers', 'percentage', 10, null, 25, 1],
            ['FREESHIP', '$7 off (shipping) on orders over $40', 'fixed', 7, 40, null, 1],
            ['VIP20', '20% off for VIP customers', 'percentage', 20, 100, 50, 1],
            ['SPRING15', 'Spring campaign (expired)', 'percentage', 15, null, null, 0],
        ] as [$code, $desc, $type, $value, $min, $max, $enabled]) {
            $coupons[$code] = $ctx->insert('store_coupons', [
                'code' => $this->uniqueValue('store_coupons', 'code', $code),
                'description' => $desc, 'type' => $type, 'value' => $value,
                'min_order_amount' => $min, 'max_discount' => $max,
                'usage_limit' => 100, 'used_count' => $code === 'WELCOME10' ? 1 : 0, 'per_customer_limit' => 1,
                'starts_at' => $enabled ? now()->subDays(30) : now()->subDays(120),
                'ends_at' => $enabled ? now()->addDays(60) : now()->subDays(60),
                'enabled' => $enabled,
            ]);
        }
        $out['Coupons'] = count($coupons);

        // Flash sale
        if ($ctx->columns('flash_sales')) {
            $fsId = $ctx->insert('flash_sales', [
                'name' => 'Weekend Flash Sale', 'is_active' => 1,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(2), 'sort_order' => 0,
            ]);
            $fsItems = 0;
            foreach (array_slice($products, 0, 3) as $sort => $pid) {
                $ctx->insert('flash_sale_product', [
                    'flash_sale_id' => $fsId, 'product_id' => $pid, 'discount_type' => 'percent',
                    'discount_value' => [15, 20, 30][$sort], 'sort_order' => $sort,
                ]);
                $fsItems++;
            }
            $out['Flash sales'] = 1;
            $out['Flash sale products'] = $fsItems;
        }

        // Online orders (pending / cancelled only) + items
        $prices = DB::table('products')->whereIn('id', $products)->pluck('price', 'id');
        $clientRows = DB::table('clients')->whereIn('id', $clients)->get()->keyBy('id');
        $orders = $items = 0;
        foreach (['pending', 'pending', 'pending', 'cancelled'] as $i => $status) {
            $clientId = $clients[$i % count($clients)];
            $client = $clientRows[$clientId] ?? null;
            $lines = [];
            foreach (array_slice($products, $i % max(1, count($products)), 2) ?: array_slice($products, 0, 1) as $pid) {
                $qty = random_int(1, 3);
                $price = round((float) ($prices[$pid] ?? 0) ?: random_int(10, 80), 2);
                $lines[] = [$pid, $qty, $price, round($qty * $price, 2)];
            }
            $subtotal = array_sum(array_column($lines, 3));
            $couponCode = $i === 0 ? 'WELCOME10' : null;
            $discount = $couponCode ? round($subtotal * 0.10, 2) : 0;
            $shipping = $i === 2 ? 0 : 6.5;
            $date = $ctx->randomDate(20);

            $orderId = $ctx->insert('online_orders', [
                'date' => $date->toDateString(),
                'time' => $date->format('H:i:s'),
                'ref' => $this->uniqueValue('online_orders', 'ref', 'SO-'.$date->format('Ymd').'-D'.random_int(1000, 9999)),
                'status' => $status,
                'client_id' => $clientId,
                'warehouse_id' => $ctx->warehouseIds[0],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_code' => $couponCode,
                'tax' => 0,
                'tax_rate' => 0,
                'shipping_cost' => $shipping,
                'shipping_method_name' => $shipping > 0 ? 'Standard Shipping' : null,
                'delivery_method' => $shipping > 0 ? 'ship' : 'pickup',
                'total' => round($subtotal - $discount + $shipping, 2),
                'customer_name' => $client->name ?? 'Guest Customer',
                'customer_email' => $client->email ?? 'guest'.random_int(1000, 9999).'@example.com',
                'customer_phone' => $client->phone ?? null,
                'shipping_address' => random_int(10, 999).' Market Street',
                'shipping_city' => $client->city ?? 'Springfield',
                'shipping_zip' => (string) random_int(10000, 99999),
                'shipping_country' => $client->country ?? 'United States',
                'payment_method' => 'cod',
                'payment_status' => 'pending',
                'created_at' => $date,
                'updated_at' => $date,
            ]);
            $orders++;
            foreach ($lines as [$pid, $qty, $price, $total]) {
                $ctx->insert('online_order_items', [
                    'order_id' => $orderId, 'product_id' => $pid, 'qty' => $qty,
                    'price' => $price, 'line_total' => $total, 'TaxNet' => 0, 'discount' => 0,
                ]);
                $items++;
            }
            if ($couponCode && $ctx->columns('store_coupon_redemptions')) {
                $ctx->insert('store_coupon_redemptions', [
                    'coupon_id' => $coupons[$couponCode], 'client_id' => $clientId,
                    'online_order_id' => $orderId, 'discount' => $discount,
                ]);
                $out['Coupon redemptions'] = ($out['Coupon redemptions'] ?? 0) + 1;
            }
        }
        $out['Online orders'] = $orders;
        $out['Order items'] = $items;

        return $out;
    }
}
