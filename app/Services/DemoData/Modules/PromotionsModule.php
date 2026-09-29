<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\Modules\Concerns\DemoRefs;

/**
 * POS promotions & discounts, bound to this company's warehouses (the
 * promotions table itself has no company_id -- warehouses are how a promotion
 * belongs to a company), a couple of product-specific ones, and a few usage
 * rows. Usages aren't tied to sales (sale_id is nullable) so nothing real is
 * touched.
 */
class PromotionsModule extends DemoModule
{
    use DemoRefs;

    public function key(): string
    {
        return 'promotions';
    }

    public function label(): string
    {
        return 'Promotions';
    }

    public function dataTables(): array
    {
        return ['promotions'];
    }

    public function requiredTables(): array
    {
        return ['promotions', 'promotion_products', 'promotion_warehouse', 'promotion_usages'];
    }

    public function generate(DemoContext $ctx): array
    {
        $products = $this->productIds($ctx, 6);
        $clients = $this->clientIds($ctx, 6);

        $defs = [
            ['Weekend 10% Off', null, 'discount', 'percentage', 10, 'all', ['starts_at' => now()->subDays(20), 'ends_at' => now()->addDays(40)]],
            ['Happy Hour', null, 'promotion', 'percentage', 15, 'all', ['time_of_day_start' => '16:00:00', 'time_of_day_end' => '18:00:00', 'starts_at' => now()->subDays(10)]],
            ['$5 Off Orders Over $50', 'SAVE5', 'discount', 'fixed', 5, 'all', ['min_cart_total' => 50, 'usage_limit_per_customer' => 3, 'starts_at' => now()->subDays(30), 'ends_at' => now()->addDays(30)]],
            ['Featured Products 20% Off', null, 'promotion', 'percentage', 20, 'specific', ['priority' => 5, 'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(25)]],
            ['Buy 3 Save 12%', 'BULK12', 'discount', 'percentage', 12, 'specific', ['min_item_count' => 3, 'stackable' => 1]],
            ['Summer Clearance (ended)', 'SUMMER25', 'promotion', 'percentage', 25, 'all', ['is_active' => 0, 'starts_at' => now()->subDays(120), 'ends_at' => now()->subDays(60), 'usage_limit_total' => 200]],
        ];

        $promos = [];
        $links = $pivots = 0;
        foreach ($defs as [$name, $code, $kind, $type, $value, $scope, $extra]) {
            $id = $ctx->insert('promotions', $extra + [
                'name' => $name,
                'code' => $code ? $this->uniqueValue('promotions', 'code', $code) : null,
                'description' => $name.' -- example promotion.',
                'kind' => $kind,
                'discount_type' => $type,
                'discount_value' => $value,
                'is_active' => 1,
                'product_scope' => $scope,
                'priority' => 0,
                'stackable' => 0,
            ]);
            $promos[] = ['id' => $id, 'code' => $code, 'type' => $type, 'value' => $value];

            foreach ($ctx->warehouseIds as $wh) {
                $ctx->insert('promotion_warehouse', ['promotion_id' => $id, 'warehouse_id' => $wh]);
                $pivots++;
            }
            if ($scope === 'specific') {
                $slice = array_slice($products, $links, 3) ?: array_slice($products, 0, 3);
                foreach ($slice as $pid) {
                    $ctx->insert('promotion_products', ['promotion_id' => $id, 'product_id' => $pid]);
                    $links++;
                }
            }
        }

        $usages = 0;
        foreach (range(1, 6) as $n) {
            $p = $promos[$n % 4]; // active ones only
            $discount = $p['type'] === 'fixed' ? $p['value'] : round(random_int(3000, 15000) / 100 * $p['value'] / 100, 2);
            $ctx->insert('promotion_usages', [
                'promotion_id' => $p['id'],
                'sale_id' => null,
                'client_id' => $ctx->pick($clients),
                'warehouse_id' => $ctx->warehouse(),
                'discount_amount' => $discount,
                'code' => $p['code'],
                'used_at' => $ctx->randomDate(15),
            ]);
            $usages++;
        }

        return [
            'Promotions' => count($promos),
            'Promotion warehouses' => $pivots,
            'Promotion products' => $links,
            'Promotion usages' => $usages,
        ];
    }
}
