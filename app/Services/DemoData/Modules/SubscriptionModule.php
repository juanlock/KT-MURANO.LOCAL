<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\Modules\Concerns\DemoRefs;
use Illuminate\Support\Facades\DB;

/**
 * Subscription products: recurring billing plans for demo customers on the
 * company's products, one per status (active / completed / canceled). No
 * invoices (sales) are generated -- the scheduler does that from
 * next_billing_date. Subscriptions carry no company_id; the client, product
 * and warehouse tie them to the company.
 */
class SubscriptionModule extends DemoModule
{
    use DemoRefs;

    private const DAYS = ['weekly' => 7, 'monthly' => 30, 'yearly' => 365];

    public function key(): string
    {
        return 'subscription_product';
    }

    public function label(): string
    {
        return 'Subscriptions';
    }

    public function dataTables(): array
    {
        return ['subscriptions'];
    }

    public function generate(DemoContext $ctx): array
    {
        $clients = $this->clientIds($ctx, 6);
        $products = $this->productIds($ctx, 6, 'is_service');
        $prices = DB::table('products')->whereIn('id', $products)->pluck('price', 'id');

        // Active plans bill yearly on purpose: subscriptions:generate-invoices
        // turns any active row whose next_billing_date has arrived into a REAL
        // (untagged) sale pointing at it, which would then block the reset.
        // Yearly billing keeps the next run ~9-11 months away.
        $plans = [
            // cycle_type, total_cycles, billing_cycle, qty, status, started days ago
            ['yearly', 3, 'yearly', 1, 'active', 40],
            ['yearly', 2, 'yearly', 2, 'active', 100],
            ['monthly', 6, 'monthly', 1, 'completed', 200],
            ['monthly', 12, 'monthly', 3, 'canceled', 150],
            ['weekly', 8, 'weekly', 1, 'canceled', 30],
        ];

        $count = 0;
        foreach ($plans as $i => [$cycleType, $totalCycles, $billing, $qty, $status, $ago]) {
            $productId = $products[$i % count($products)];
            $unit = round((float) ($prices[$productId] ?? 0) ?: random_int(20, 90), 2);
            $totalDays = $totalCycles * self::DAYS[$cycleType];
            $cycles = max(1, intdiv($totalDays, self::DAYS[$billing]));
            $billed = min($cycles, intdiv($ago, self::DAYS[$billing]) + 1);
            $remaining = match ($status) {
                'completed' => 0,
                'canceled' => max(0, $cycles - $billed),
                default => max(1, $cycles - $billed),
            };
            $start = now()->subDays($ago);

            $ctx->insert('subscriptions', [
                'date' => $start->toDateString(),
                'user_id' => $ctx->userId,
                'client_id' => $clients[$i % count($clients)],
                'product_id' => $productId,
                'warehouse_id' => $ctx->warehouseIds[0],
                'cycle_type' => $cycleType,
                'total_cycles' => $totalCycles,
                'billing_cycle' => $billing,
                'remaining_cycles' => $remaining,
                'price_per_cycle' => round($unit * $qty, 2),
                'price_per_unit' => $unit,
                'quantity' => $qty,
                'next_billing_date' => $start->copy()->addDays($billed * self::DAYS[$billing])->toDateString(),
                'status' => $status,
            ]);
            $count++;
        }

        return ['Subscriptions' => $count];
    }
}
