<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\Modules\Concerns\DemoRefs;
use Illuminate\Support\Facades\DB;

/**
 * Bookings / appointments: a few trays (resources/stations) and bookings
 * spread over past and upcoming days in every status. Bookings prefer the
 * company's service products. Neither table has company_id; the customer
 * ties a booking to the company. No Google Calendar event is created.
 */
class BookingsModule extends DemoModule
{
    use DemoRefs;

    public function key(): string
    {
        return 'bookings';
    }

    public function label(): string
    {
        return 'Bookings';
    }

    public function dataTables(): array
    {
        return ['bookings'];
    }

    public function requiredTables(): array
    {
        return ['bookings', 'trays'];
    }

    public function generate(DemoContext $ctx): array
    {
        $clients = $this->clientIds($ctx, 6);
        $products = DB::table('products')->whereIn('id', $this->productIds($ctx, 6, 'is_service'))
            ->get(['id', 'name', 'price'])->values();

        $trays = [];
        foreach ([['Station 1', 'Front room chair'], ['Station 2', 'Back room chair'], ['Private Room', 'Quiet room for longer sessions']] as [$name, $desc]) {
            $trays[] = $ctx->insert('trays', ['name' => $name, 'description' => $desc]);
        }

        $staff = DB::table('users')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
            ->where('statut', 1)->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [$ctx->userId];

        $plan = [
            // days offset, time, duration min, status
            [-14, '10:00', 60, 'completed'],
            [-7, '14:30', 45, 'completed'],
            [-3, '11:00', 30, 'cancelled'],
            [0, '16:00', 60, 'confirmed'],
            [2, '09:30', 90, 'confirmed'],
            [5, '13:00', 45, 'pending'],
            [9, '15:30', 60, 'pending'],
        ];

        $ref = $this->nextRef('bookings', 'BK');
        $count = 0;
        foreach ($plan as $i => [$offset, $time, $minutes, $status]) {
            $product = $products[$i % count($products)];
            $price = round((float) $product->price ?: random_int(25, 120), 2);
            $start = now()->addDays($offset)->setTimeFromTimeString($time);
            $ctx->insert('bookings', [
                'Ref' => 'BK_'.($ref++),
                'customer_id' => $clients[$i % count($clients)],
                'product_id' => $product->id,
                'tray_id' => $trays[$i % count($trays)],
                'sales_person_id' => $ctx->pick($staff),
                'product_name' => $product->name,
                'product_description' => null,
                'price' => $price,
                'amount' => $price,
                'booking_date' => $start->toDateString(),
                'booking_time' => $start->format('H:i:s'),
                'booking_end_time' => $start->copy()->addMinutes($minutes)->format('H:i:s'),
                'status' => $status,
                'notes' => $status === 'cancelled' ? 'Customer called to cancel.' : ($i === 4 ? 'First visit -- allow extra time.' : null),
            ]);
            $count++;
        }

        return ['Trays' => count($trays), 'Bookings' => $count];
    }
}
