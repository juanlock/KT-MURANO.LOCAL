<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\Modules\Concerns\DemoRefs;
use Illuminate\Support\Facades\DB;

/**
 * Bookings / appointments: a few trays (resources/stations) and bookings
 * spread over past and upcoming days in every status. Bookings prefer the
 * company's service products. Trays are the company's own (trays.company_id,
 * set by DemoContext::insert); a booking has no company_id -- the customer
 * ties it to the company. No Google Calendar event is created.
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
        if (in_array($ctx->businessType, ['barber', 'salon'], true)) {
            return $this->shopDay($ctx);
        }
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

    /**
     * A barber shop / salon's agenda (2026-10-27: Urban Cuts had none): its chairs, and
     * from three weeks ago to two weeks ahead, Monday to Saturday, several appointments a
     * day per barber / stylist with the company's own services -- done or cancelled in the
     * past, confirmed today, confirmed / pending ahead. No barber is booked twice at once.
     */
    private function shopDay(DemoContext $ctx): array
    {
        $clients = $this->clientIds($ctx, 12);
        $services = DB::table('products')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->where('type', 'is_service')
            ->get(['id', 'name', 'price'])->values();
        if ($services->isEmpty()) {
            $services = DB::table('products')->whereIn('id', $this->productIds($ctx, 6, 'is_service'))->get(['id', 'name', 'price'])->values();
        }
        $chair = $ctx->businessType === 'barber' ? ($ctx->es ? 'Silla' : 'Chair') : ($ctx->es ? 'Puesto' : 'Station');
        $trays = [];
        for ($n = 1; $n <= 3; $n++) {
            $trays[] = $ctx->insert('trays', ['name' => "$chair $n", 'description' => null]);
        }
        // The people on the chairs: employees whose position is a barber / stylist (HRM demo), else any staff login.
        $staff = DB::table('employees as e')->join('designations as d', 'd.id', '=', 'e.designation_id')
            ->where('e.company_id', $ctx->companyId)->whereNull('e.deleted_at')->whereNotNull('e.user_id')
            ->where(fn ($q) => $q->where('d.designation', 'like', '%arber%')->orWhere('d.designation', 'like', '%stilist%')
                ->orWhere('d.designation', 'like', '%tylist%')->orWhere('d.designation', 'like', '%olorist%')->orWhere('d.designation', 'like', '%anicur%'))
            ->pluck('e.user_id')->map(fn ($v) => (int) $v)->all()
            ?: DB::table('users')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->where('statut', 1)->where('type', 'employee')->pluck('id')->map(fn ($v) => (int) $v)->all()
            ?: [$ctx->userId];
        $minutes = function (string $name): int {
            $n = mb_strtolower($name);

            return match (true) {
                str_contains($n, '+') || str_contains($n, 'hijo') || str_contains($n, 'combo') || str_contains($n, 'tinte') || str_contains($n, 'color') => 60,
                str_contains($n, 'barba') || str_contains($n, 'beard') || str_contains($n, 'lavado') || str_contains($n, 'wash') || str_contains($n, 'mascarilla') => 30,
                default => 45,
            };
        };

        $ref = $this->nextRef('bookings', 'BK');
        $count = 0;
        for ($d = -21; $d <= 14; $d++) {
            $day = now()->addDays($d);
            if ($day->isSunday()) {
                continue;
            }
            foreach ($staff as $s => $barber) {
                // 2-4 appointments per barber, on free half-hour slots between 09:00 and 19:00
                $free = 9 * 60;
                $todo = random_int(2, 4);
                while ($todo-- > 0 && $free < 19 * 60) {
                    $free += $ctx->pick([0, 0, 30, 60]);
                    $service = $services[random_int(0, $services->count() - 1)];
                    $len = $minutes((string) $service->name);
                    if ($free + $len > 20 * 60) {
                        break;
                    }
                    $start = $day->copy()->setTime(intdiv($free, 60), $free % 60);
                    $status = $d < 0 ? (random_int(1, 10) === 1 ? 'cancelled' : 'completed') : ($d === 0 ? 'confirmed' : (random_int(1, 10) <= 6 ? 'confirmed' : 'pending'));
                    $price = round((float) $service->price ?: 30, 2);
                    $ctx->insert('bookings', [
                        'Ref' => 'BK_'.($ref++),
                        'customer_id' => $clients[array_rand($clients)],
                        'product_id' => $service->id,
                        'tray_id' => $trays[$s % count($trays)],
                        'warehouse_id' => $ctx->warehouse(),
                        'sales_person_id' => $barber,
                        'product_name' => $service->name,
                        'price' => $price,
                        'amount' => $price,
                        'booking_date' => $start->toDateString(),
                        'booking_time' => $start->format('H:i:s'),
                        'booking_end_time' => $start->copy()->addMinutes($len)->format('H:i:s'),
                        'status' => $status,
                        'notes' => $status === 'cancelled' ? ($ctx->es ? 'El cliente llamó para cancelar.' : 'Customer called to cancel.') : null,
                    ]);
                    $free += $len;
                    $count++;
                }
            }
        }

        return ['Trays' => count($trays), 'Bookings' => $count];
    }
}
