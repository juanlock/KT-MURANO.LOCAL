<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A transport demo that is alive every day (user's request, 2026-11-02, for the
 * presentations): the demo made by TransportModule (30 days of trips back, a week
 * ahead) would age -- in a week no trip ahead, the board empty. For a company with
 * transport demo trips (demo_records, module transport):
 *  - days(): every day up to a week ahead gets its trips, copied from the same
 *    weekday-pattern 6 days before (the crews rotate every 2 and 3 days, the buses
 *    every 2 trips, so a 6-day copy keeps nobody / no bus on two trips at once);
 *  - statuses(): trips that already arrived = completed (now and then cancelled),
 *    on the road = in progress;
 *  - trim(): demo trips older than 30 days removed (crew included);
 *  - maintenance(): the demo jobs keep their place around today (one in the
 *    workshop since yesterday, one scheduled next week);
 *  - complaints(): one new demo complaint a day in the demo complaints book.
 * Only demo rows are touched; the company's own trips never. Safe to run often
 * (`php artisan transport:demo-day`, hourly).
 */
class TransportDemoDay
{
    private const MODULE = 'transport';

    public static function run(int $companyId): array
    {
        return [
            'days' => self::days($companyId), 'statuses' => self::statuses($companyId), 'trimmed' => self::trim($companyId),
            'maintenance' => self::maintenance($companyId), 'complaints' => self::complaints($companyId),
            'tickets' => self::tickets($companyId),
        ];
    }

    /**
     * Ticket sales at the agencies' Terminal POS (2026-11-03): from the last demo
     * ticket sale (or 30 days back) up to this moment, every hour from 06:00 to
     * 21:59, more at the rush hours (07-09, 17-19). Each sale: a ticket of a route
     * leaving from that agency, 1-4 passengers, a demo passenger or the walk-in
     * customer, cash or card, rung up by the agency's cashier on that day's cash
     * register (past days' registers closed with their totals, today's open).
     */
    public static function tickets(int $cid): int
    {
        $tickets = DB::table('products')->whereIn('id', self::ids($cid, 'products'))->where('code', 'like', 'PAS-%')->whereNull('deleted_at')->get(['id', 'code', 'price']);
        if ($tickets->isEmpty()) {
            return 0;
        }
        $byWarehouse = [];
        foreach ($tickets as $t) {
            $wid = DB::table('transport_routes as r')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')
                ->where('r.id', (int) substr($t->code, 4))->value('o.warehouse_id');
            if ($wid) {
                $byWarehouse[(int) $wid][] = $t;
            }
        }
        $roleId = DB::table('roles')->where('company_id', $cid)->where('name', TransportAgencies::POS_ROLE)->whereNull('deleted_at')->value('id');
        $cashiers = DB::table('user_warehouse as uw')->join('users as u', 'u.id', '=', 'uw.user_id')
            ->whereIn('u.id', self::ids($cid, 'users'))->where('u.role_id', $roleId)->whereNull('u.deleted_at')
            ->pluck('u.id', 'uw.warehouse_id')->map(fn ($v) => (int) $v)->all();
        $passengers = self::ids($cid, 'clients');
        $walkIn = (int) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('client_id');
        $last = DB::table('sales')->whereIn('id', self::ids($cid, 'sales'))->max('created_at');
        $start = $last ? \Carbon\Carbon::parse($last)->addMinute() : now()->subDays(30)->startOfDay();
        $numbers = new \App\Http\Controllers\SalesController;
        $made = 0;

        for ($hour = $start->copy()->startOfHour(); $hour->lt(now()); $hour->addHour()) {
            if ($hour->hour < 6 || $hour->hour > 21) {
                continue;
            }
            $rush = in_array($hour->hour, [7, 8, 9, 17, 18, 19], true);
            DB::transaction(function () use ($cid, $hour, $rush, $byWarehouse, $cashiers, $passengers, $walkIn, $start, $numbers, &$made) {
                foreach ($byWarehouse as $wid => $routeTickets) {
                    $cashier = $cashiers[$wid] ?? null;
                    if (! $cashier) {
                        continue;
                    }
                    for ($k = $rush ? random_int(1, 3) : random_int(0, 1); $k > 0; $k--) {
                        $at = $hour->copy()->addMinutes(random_int(0, 59))->addSeconds(random_int(0, 59));
                        if ($at->lt($start) || $at->gt(now())) {
                            continue;
                        }
                        $t = $routeTickets[array_rand($routeTickets)];
                        $roll = random_int(1, 10);
                        $qty = $roll <= 6 ? 1 : ($roll <= 9 ? 2 : random_int(3, 4));
                        $total = round($qty * (float) $t->price, 2);
                        $register = self::register($cid, $cashier, $wid, $at);
                        $sale = DB::table('sales')->insertGetId([
                            'company_id' => $cid, 'user_id' => $cashier, 'date' => $at->toDateString(), 'time' => $at->format('H:i:s'),
                            'Ref' => $numbers->getNumberOrder($wid, $cid), 'is_pos' => 1,
                            'client_id' => $passengers && random_int(1, 100) <= 65 ? $passengers[array_rand($passengers)] : $walkIn,
                            'warehouse_id' => $wid, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                            'GrandTotal' => $total, 'paid_amount' => $total, 'payment_statut' => 'paid', 'statut' => 'completed',
                            'cash_register_id' => $register, 'created_at' => $at, 'updated_at' => $at,
                        ]);
                        self::tag($cid, 'sales', $sale);
                        self::tag($cid, 'sale_details', DB::table('sale_details')->insertGetId([
                            'date' => $at->toDateString(), 'sale_id' => $sale, 'product_id' => $t->id, 'quantity' => $qty, 'price' => $t->price, 'total' => $total,
                            'TaxNet' => 0, 'discount' => 0, 'discount_method' => '2', 'tax_method' => '1', 'created_at' => $at, 'updated_at' => $at,
                        ]));
                        self::tag($cid, 'payment_sales', DB::table('payment_sales')->insertGetId([
                            'user_id' => $cashier, 'date' => $at->toDateString(), 'Ref' => 'PS-'.$sale, 'sale_id' => $sale, 'montant' => $total, 'change' => 0,
                            'payment_method_id' => random_int(1, 10) <= 6 ? 2 : 1, 'created_at' => $at, 'updated_at' => $at,
                        ]));
                        $made++;
                    }
                }
            });
        }
        self::closeRegisters($cid);

        return $made;
    }

    /** The cashier's register of that day at that agency (opened at 06:00 with S/ 200 change). */
    private static function register(int $cid, int $userId, int $wid, $at): int
    {
        $id = DB::table('cash_registers')->whereIn('id', self::ids($cid, 'cash_registers'))->where('user_id', $userId)->where('warehouse_id', $wid)
            ->whereDate('opened_at', $at->toDateString())->value('id');
        if ($id) {
            return (int) $id;
        }
        $opened = $at->copy()->setTime(6, 0);
        $id = DB::table('cash_registers')->insertGetId([
            'user_id' => $userId, 'warehouse_id' => $wid, 'opening_balance' => 200, 'closing_balance' => 0, 'total_sales' => 0,
            'cash_in' => 0, 'cash_out' => 0, 'difference' => 0, 'status' => 'open', 'opened_at' => $opened, 'created_at' => $opened, 'updated_at' => $opened,
        ]);
        self::tag($cid, 'cash_registers', $id);

        return (int) $id;
    }

    /** Past days' demo registers closed at 22:00 with their totals (cash counted right). */
    private static function closeRegisters(int $cid): void
    {
        $open = DB::table('cash_registers')->whereIn('id', self::ids($cid, 'cash_registers'))->where('status', 'open')
            ->where('opened_at', '<', now()->startOfDay())->get(['id', 'opening_balance', 'opened_at']);
        foreach ($open as $r) {
            $total = (float) DB::table('sales')->where('cash_register_id', $r->id)->sum('GrandTotal');
            $cash = (float) DB::table('payment_sales')->join('sales', 'sales.id', '=', 'payment_sales.sale_id')
                ->where('sales.cash_register_id', $r->id)->where('payment_sales.payment_method_id', 2)->sum('payment_sales.montant');
            $closed = \Carbon\Carbon::parse($r->opened_at)->setTime(22, 0);
            DB::table('cash_registers')->where('id', $r->id)->update([
                'status' => 'closed', 'closed_at' => $closed, 'total_sales' => $total, 'closing_balance' => (float) $r->opening_balance + $cash,
                'difference' => 0, 'updated_at' => $closed,
            ]);
        }
    }

    /** Companies with transport demo trips. */
    public static function companies(): array
    {
        return DB::table('demo_records')->where('module', self::MODULE)->where('record_type', 'transport_trips')
            ->distinct()->pluck('company_id')->map(fn ($v) => (int) $v)->all();
    }

    private static function ids(int $cid, string $table): array
    {
        return DB::table('demo_records')->where('company_id', $cid)->where('module', self::MODULE)->where('record_type', $table)
            ->pluck('record_id')->map(fn ($v) => (int) $v)->all();
    }

    private static function tag(int $cid, string $table, int $id): void
    {
        DB::table('demo_records')->insert(['company_id' => $cid, 'module' => self::MODULE, 'record_type' => $table, 'record_id' => $id,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** The days after the last demo day, up to a week ahead, copied from 6 days before. */
    public static function days(int $cid): int
    {
        $ids = self::ids($cid, 'transport_trips');
        if (! $ids) {
            return 0;
        }
        $last = DB::table('transport_trips')->whereIn('id', $ids)->whereNull('deleted_at')->max('departure_at');
        if (! $last) {
            return 0;
        }
        $made = 0;
        $end = now()->startOfDay()->addDays(7);
        for ($day = \Carbon\Carbon::parse($last)->startOfDay()->addDay(); $day->lte($end); $day->addDay()) {
            $source = $day->copy()->subDays(6);
            $trips = DB::table('transport_trips')->whereIn('id', self::ids($cid, 'transport_trips'))->whereNull('deleted_at')
                ->whereBetween('departure_at', [$source->copy()->startOfDay(), $source->copy()->endOfDay()])->orderBy('departure_at')->get();
            if ($trips->isEmpty()) {
                break; // nothing to copy from: the demo was reset or never had that day
            }
            DB::transaction(function () use ($trips, $cid, &$made) {
                foreach ($trips as $t) {
                    $dep = \Carbon\Carbon::parse($t->departure_at)->addDays(6);
                    $arr = $t->arrival_at ? \Carbon\Carbon::parse($t->arrival_at)->addDays(6) : null;
                    $id = DB::table('transport_trips')->insertGetId([
                        'company_id' => $cid, 'route_id' => $t->route_id, 'vehicle_id' => $t->vehicle_id, 'departure_at' => $dep, 'arrival_at' => $arr,
                        'status' => self::statusAt($dep, $arr, 0), 'notes' => $t->notes, 'created_by' => $t->created_by,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    self::tag($cid, 'transport_trips', $id);
                    foreach (DB::table('transport_trip_crew')->where('trip_id', $t->id)->get() as $c) {
                        $row = (array) $c;
                        unset($row['id']);
                        $row['trip_id'] = $id;
                        DB::table('transport_trip_crew')->insert($row);
                    }
                    $made++;
                }
            });
        }

        return $made;
    }

    /** What a trip is at this moment; one in 17 arrived trips was cancelled (like the demo). */
    private static function statusAt($dep, $arr, int $id): string
    {
        if ($arr && $arr->isPast()) {
            return $id % 17 === 5 ? 'cancelled' : 'completed';
        }

        return $dep->isPast() ? 'in_progress' : 'scheduled';
    }

    /** Demo trips follow the clock: on the road = in progress, arrived = completed. */
    public static function statuses(int $cid): int
    {
        $ids = self::ids($cid, 'transport_trips');
        if (! $ids) {
            return 0;
        }
        $n = 0;
        foreach (DB::table('transport_trips')->whereIn('id', $ids)->whereNull('deleted_at')->whereIn('status', ['scheduled', 'in_progress'])
            ->where('departure_at', '<=', now())->get(['id', 'departure_at', 'arrival_at', 'status']) as $t) {
            $status = self::statusAt(\Carbon\Carbon::parse($t->departure_at), $t->arrival_at ? \Carbon\Carbon::parse($t->arrival_at) : null, (int) $t->id);
            if ($status !== $t->status) {
                DB::table('transport_trips')->where('id', $t->id)->update(['status' => $status, 'updated_at' => now()]);
                $n++;
            }
        }

        return $n;
    }

    /** Demo trips older than 30 days go away (with their crew and tags). */
    public static function trim(int $cid): int
    {
        $ids = self::ids($cid, 'transport_trips');
        if (! $ids) {
            return 0;
        }
        $old = DB::table('transport_trips')->whereIn('id', $ids)->where('departure_at', '<', now()->startOfDay()->subDays(30))->pluck('id')->all();
        if (! $old) {
            return 0;
        }
        DB::transaction(function () use ($old, $cid) {
            DB::table('transport_trip_crew')->whereIn('trip_id', $old)->delete();
            DB::table('transport_trips')->whereIn('id', $old)->delete();
            DB::table('demo_records')->where('company_id', $cid)->where('module', self::MODULE)->where('record_type', 'transport_trips')->whereIn('record_id', $old)->delete();
        });

        return count($old);
    }

    /** The demo's workshop job stays "since yesterday", the scheduled one "next week". */
    public static function maintenance(int $cid): int
    {
        $ids = self::ids($cid, 'vehicle_maintenances');
        if (! $ids) {
            return 0;
        }
        $n = DB::table('vehicle_maintenances')->whereIn('id', $ids)->where('status', 'in_progress')
            ->whereDate('service_date', '<', now()->subDay()->toDateString())->update(['service_date' => now()->subDay()->toDateString(), 'updated_at' => now()]);
        $n += DB::table('vehicle_maintenances')->whereIn('id', $ids)->where('status', 'scheduled')
            ->whereDate('service_date', '<', now()->toDateString())->update(['service_date' => now()->addDays(6)->toDateString(), 'updated_at' => now()]);

        return $n;
    }

    /** One new complaint a day in the demo complaints book (a copy of an older one). */
    public static function complaints(int $cid): int
    {
        $forms = self::ids($cid, 'forms');
        $subs = self::ids($cid, 'form_submissions');
        if (! $forms || ! $subs) {
            return 0;
        }
        $today = DB::table('form_submissions')->whereIn('id', $subs)->whereIn('form_id', $forms)->whereDate('created_at', now()->toDateString())->exists();
        $model = DB::table('form_submissions')->whereIn('id', $subs)->whereIn('form_id', $forms)->inRandomOrder()->first();
        if ($today || ! $model) {
            return 0;
        }
        $at = now()->subMinutes(random_int(5, max(6, now()->hour * 60 + now()->minute)));
        DB::transaction(function () use ($model, $cid, $at) {
            $number = (int) DB::table('forms')->where('id', $model->form_id)->lockForUpdate()->value('next_number') ?: 1;
            $id = DB::table('form_submissions')->insertGetId(['form_id' => $model->form_id, 'number' => $number, 'data' => $model->data,
                'is_read' => 0, 'ip' => '127.0.0.1', 'created_at' => $at, 'updated_at' => $at]);
            DB::table('forms')->where('id', $model->form_id)->update(['next_number' => $number + 1]);
            self::tag($cid, 'form_submissions', $id);
        });

        return 1;
    }
}
