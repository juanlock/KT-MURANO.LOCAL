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

    /** How far back the demo's departures go (user's request, 2026-11-11: two months; 2026-11-12: six months, to see the trend). */
    private const HISTORY_DAYS = 182;

    public static function run(int $companyId): array
    {
        return [
            'days' => self::days($companyId), 'schedule' => self::schedule($companyId), 'statuses' => self::statuses($companyId), 'trimmed' => self::trim($companyId),
            'maintenance' => self::maintenance($companyId), 'complaints' => self::complaints($companyId),
            'tickets' => self::tickets($companyId),
            'control' => self::control($companyId),
            'services' => self::services($companyId),
            'profit' => self::profitData($companyId),
        ];
    }

    /**
     * What the demo's profit panel needs (2026-11-04), on demo rows only: seats, cargo and
     * depreciation data on the demo buses, each ticket sale's passengers on a departure (each
     * trip filling up to its own level, so some pay and some don't), expected tolls on the
     * routes, real fuel on finished trips and a few parcels. $all = every demo sale, else the
     * last 2 days'. Safe to run often.
     */
    public static function profitData(int $cid, bool $all = false): array
    {
        // Older migrations call this before the tables of the trips with stops exist: the last one does the work.
        if (! \Illuminate\Support\Facades\Schema::hasTable('transport_route_stops') || ! \Illuminate\Support\Facades\Schema::hasColumn('transport_parcels', 'tracking_token')) {
            return [];
        }
        $buses = self::ids($cid, 'vehicles');
        DB::table('vehicles')->whereIn('id', $buses)->whereNull('seats')->update(['seats' => 40]);
        // Filled in one by one: a bus whose seats are the company's own still gets the demo's other data.
        DB::table('vehicles')->whereIn('id', $buses)->whereNull('cargo_volume_m3')->update(['cargo_volume_m3' => 12, 'cargo_weight_kg' => 2000]);
        DB::table('vehicles')->whereIn('id', $buses)->whereNull('useful_life_km')->update(['useful_life_km' => 1000000,
            'residual_value' => DB::raw('ROUND(COALESCE(purchase_price, 0) * 0.2, 2)')]);

        $trips = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->whereIn('t.id', self::ids($cid, 'transport_trips'))->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')
            // The hourly run (2026-11-12, six months of demo): only the trips that can still change -- from 3 days ago on --
            // and older ones never filled yet (no ticket: a new history). $all = every demo trip.
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->where('t.departure_at', '>=', now()->subDays(3)->startOfDay())
                ->orWhereNotExists(fn ($e) => $e->select(DB::raw(1))->from('transport_tickets as k')->whereColumn('k.trip_id', 't.id'))))
            ->orderBy('t.departure_at')
            ->get(['t.id', 't.route_id', 't.departure_at', 't.status', 't.fuel_cost', 'r.distance_km', 'v.seats', 'v.seats_lower', 'v.vip_seats']);

        // Expected tolls on the routes these trips use (about one every 150 km).
        foreach ($trips->unique('route_id') as $t) {
            DB::table('transport_routes')->where('id', $t->route_id)->whereNull('toll_cost')->update(['toll_cost' => max(1, (int) round((float) $t->distance_km / 150)) * 45]);
        }
        $tickets = self::demoPassengers($cid, $trips, $all);
        $fuel = 0;
        foreach ($trips->where('status', 'completed')->whereNull('fuel_cost') as $t) {
            // Diesel: about S/ 1,500 from Lima to Chepén, 698 km (user's data, 2026-11-04) = S/ 2.15 per km, a little different each trip.
            DB::table('transport_trips')->where('id', $t->id)->update(['fuel_cost' => round((float) $t->distance_km * 2.15 * (0.9 + (crc32('fuel'.$t->id) % 21) / 100), 2)]);
            $fuel++;
        }
        $topped = self::demoTopUp($cid, $trips);

        return ['tickets' => $tickets, 'topped_up' => $topped, 'fuel' => $fuel, 'parcels' => self::demoParcels($cid, $trips)];
    }

    /**
     * The share of seats a demo trip fills at its fullest, always the same for that trip. 2026-11-12 (user, calibrated to the
     * client's real report of 04-11: S/ 37,838 in 15 departures = about S/ 2,500 a departure, ~S/ 1.1 M a month): 20 % to 70 %
     * (it was 30 % to 100 %, which gave ~S/ 2.7 M a month and a profit no owner would believe).
     */
    private static function demoFill(int $tripId): float
    {
        return 0.20 + (crc32('fill'.$tripId) % 51) / 100;
    }

    /** The seats already taken on each demo trip, with their stretch and how many went as VIP. */
    private static function seatState($trips): array
    {
        $state = [];
        $byId = [];
        foreach ($trips as $t) {
            $state[$t->id] = ['occ' => [], 'vip' => 0];
            $byId[$t->id] = (int) $t->route_id;
        }
        // By id, not firstWhere(): 6 months of demo = ~85,000 tickets x ~3,600 trips took 346 s (2026-11-12).
        foreach (DB::table('transport_tickets')->whereIn('trip_id', array_keys($byId) ?: [0])->whereNotNull('seat_number')->get(['trip_id', 'seat_number', 'from_location_id', 'to_location_id', 'is_vip']) as $x) {
            $route = $byId[$x->trip_id];
            [$a, $b] = TransportLegs::ticketStretch($route, $x);
            $state[$x->trip_id]['occ'][] = [(int) $x->seat_number, $a, $b];
            $state[$x->trip_id]['vip'] += $x->is_vip ? 1 : 0;
        }

        return $state;
    }

    /**
     * A seat for the stretch [a, b) of a demo trip, like an airline (user's data, 2026-11-04): about 60 % of the
     * 15 VIP seats are sold as VIP (20 % dearer); then the regular ones; when those run out, on 1 trip in 3
     * a last-minute passenger gets an empty VIP seat at the regular price, else it stays empty.
     * Returns [seat, sold as VIP] or null.
     */
    private static function takeSeat(array &$state, object $t, int $a, int $b): ?array
    {
        $map = TransportLegs::seatMap($t);
        $taken = [];
        foreach ($state[$t->id]['occ'] as [$seat, $x, $y]) {
            if ($x < $b && $y > $a) {
                $taken[$seat] = true;
            }
        }
        if (count($taken) >= ceil(count($map) * self::demoFill((int) $t->id))) {
            return null;
        }
        $free = fn (bool $vip) => collect($map)->first(fn ($s) => $s['vip'] === $vip && ! isset($taken[$s['number']]));
        $quota = 7 + crc32('vip'.$t->id) % 5; // 7 to 11 of the 15
        if ($state[$t->id]['vip'] < $quota && ($s = $free(true))) {
            $state[$t->id]['vip']++;
            $state[$t->id]['occ'][] = [$s['number'], $a, $b];

            return [$s['number'], true];
        }
        $s = $free(false) ?: (crc32('late'.$t->id) % 3 === 0 ? $free(true) : null);
        if (! $s) {
            return null;
        }
        $state[$t->id]['occ'][] = [$s['number'], $a, $b];

        return [$s['number'], false];
    }

    private static function insertDemoTicket(int $cid, object $t, object $line, array $seat, array $names, int $from, int $to, float $price, $at): void
    {
        self::tag($cid, 'transport_tickets', DB::table('transport_tickets')->insertGetId([
            'company_id' => $cid, 'sale_id' => $line->sale_id, 'product_id' => $line->product_id, 'route_id' => $line->route_id, 'trip_id' => $t->id, 'warehouse_id' => $line->warehouse_id,
            'seat_number' => $seat[0], 'is_vip' => $seat[1], 'from_location_id' => $from, 'to_location_id' => $to, 'code' => TransportLegs::ticketCode(), 'doc_type' => 'dni', 'passenger_type' => 'adult',
            'dni' => (string) random_int(10000000, 79999999), 'first_name' => $names[0], 'last_name' => $names[1], 'luggage' => random_int(1, 10) <= 7,
            'price' => round($seat[1] ? $price * 1.2 : $price, 2), 'created_at' => $at, 'updated_at' => $at,
        ]));
    }

    /** A demo trip that ran below its share of seats gets one more sale, made 2 hours before it left. */
    private static function demoTopUp(int $cid, $trips): int
    {
        $products = DB::table('products')->whereIn('id', self::ids($cid, 'products'))->where('code', 'like', 'PAS-%')->where('code', 'not like', '%-VIP')->whereNull('deleted_at')->get(['id', 'code', 'price'])
            ->keyBy(fn ($p) => (int) substr($p->code, 4));
        $routes = DB::table('transport_routes as r')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->whereIn('r.id', $trips->pluck('route_id')->unique())->get(['r.id', 'r.origin_id', 'r.destination_id', 'o.warehouse_id'])->keyBy('id');
        $roleId = DB::table('roles')->where('company_id', $cid)->where('name', TransportAgencies::POS_ROLE)->whereNull('deleted_at')->value('id');
        $cashiers = DB::table('user_warehouse as uw')->join('users as u', 'u.id', '=', 'uw.user_id')->whereIn('u.id', self::ids($cid, 'users'))->where('u.role_id', $roleId)->whereNull('u.deleted_at')
            ->pluck('u.id', 'uw.warehouse_id');
        $walkIn = (int) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('client_id');
        $numbers = new \App\Http\Controllers\SalesController;
        $state = self::seatState($trips);
        $made = 0;
        foreach ($trips as $t) {
            // Gone: up to its share. Leaving within a day (2026-11-11, the day's screen showed 0 passengers until the bus
            // left): the seats sell as the departure comes near, sold now.
            $dep = \Carbon\Carbon::parse($t->departure_at);
            if (in_array($t->status, ['completed', 'in_progress'], true)) {
                $part = 1.0;
                $at = $dep->copy()->subHours(2);
            } elseif ($t->status === 'scheduled' && $dep->isFuture() && $dep->lte(now()->addDay())) {
                $part = max(0.0, min(1.0, 1 - now()->diffInMinutes($dep) / 1440));
                $at = now();
            } else {
                continue;
            }
            $route = $routes[$t->route_id] ?? null;
            $product = $products[$t->route_id] ?? null;
            $wid = (int) ($route->warehouse_id ?? 0);
            $cashier = $cashiers[$wid] ?? null;
            if (! $route || ! $product || ! $cashier) {
                continue;
            }
            $last = count(TransportLegs::path((int) $t->route_id)) - 1;
            $seats = (int) $t->seats ?: 40;
            // Seats free over the whole route, up to the trip's share.
            $busy = collect($state[$t->id]['occ'])->pluck(0)->unique()->count();
            $missing = (int) ceil($seats * self::demoFill((int) $t->id) * $part) - $busy;
            if ($missing <= 0) {
                continue;
            }
            DB::transaction(function () use ($cid, $t, $route, $product, $wid, $cashier, $walkIn, $numbers, $missing, $last, $at, &$state, &$made) {
                $total = round($missing * (float) $product->price, 2);
                $sale = DB::table('sales')->insertGetId(['company_id' => $cid, 'user_id' => $cashier, 'date' => $at->toDateString(), 'time' => $at->format('H:i:s'),
                    'Ref' => $numbers->getNumberOrder($wid, $cid), 'is_pos' => 1, 'client_id' => $walkIn, 'warehouse_id' => $wid, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                    'GrandTotal' => $total, 'paid_amount' => $total, 'payment_statut' => 'paid', 'statut' => 'completed', 'created_at' => $at, 'updated_at' => $at]);
                self::tag($cid, 'sales', $sale);
                self::tag($cid, 'sale_details', DB::table('sale_details')->insertGetId(['date' => $at->toDateString(), 'sale_id' => $sale, 'product_id' => $product->id, 'quantity' => $missing,
                    'price' => $product->price, 'total' => $total, 'TaxNet' => 0, 'discount' => 0, 'discount_method' => '2', 'tax_method' => '1', 'created_at' => $at, 'updated_at' => $at]));
                self::tag($cid, 'payment_sales', DB::table('payment_sales')->insertGetId(['user_id' => $cashier, 'date' => $at->toDateString(), 'Ref' => 'PS-'.$sale, 'sale_id' => $sale, 'montant' => $total,
                    'change' => 0, 'payment_method_id' => 2, 'created_at' => $at, 'updated_at' => $at]));
                $line = (object) ['sale_id' => $sale, 'product_id' => $product->id, 'route_id' => $t->route_id, 'warehouse_id' => $wid];
                for ($i = 0; $i < $missing; $i++) {
                    if (! ($seat = self::takeSeat($state, $t, 0, $last))) {
                        break;
                    }
                    self::insertDemoTicket($cid, $t, $line, $seat, ['Pasajero', 'Demo '.($i + 1)], (int) $route->origin_id, (int) $route->destination_id, (float) $product->price, $at);
                    $made++;
                }
            });
        }

        return $made;
    }

    /** Demo ticket sales without passengers get them, on the next bus that goes from there to there with a seat free on that stretch. */
    private static function demoPassengers(int $cid, $trips, bool $all): int
    {
        $state = self::seatState($trips);
        $done = array_flip(DB::table('transport_tickets')->where('company_id', $cid)->distinct()->pluck('sale_id')->all());
        $lines = DB::table('sales as s')->join('sale_details as d', 'd.sale_id', '=', 's.id')->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->whereIn('s.id', self::ids($cid, 'sales'))->where('p.code', 'like', 'PAS-%')
            ->when(! $all, fn ($q) => $q->where('s.created_at', '>=', now()->subDays(2)))
            ->orderBy('s.created_at')->get(['s.id as sale_id', 's.warehouse_id', 's.created_at', 'd.product_id', 'd.quantity', 'd.total', 'p.code', 'c.firstname', 'c.lastname']);
        $routes = DB::table('transport_routes')->where('company_id', $cid)->get(['id', 'origin_id', 'destination_id'])->keyBy('id');
        $firsts = ['Ana', 'Luis', 'María', 'Carlos', 'Rosa', 'Jorge', 'Elena', 'Pedro', 'Lucía', 'Miguel'];
        $lasts = ['Quispe', 'Flores', 'Sánchez', 'García', 'Rojas', 'Díaz', 'Torres', 'Vargas', 'Ramos', 'Castillo'];
        $made = 0;
        foreach ($lines as $l) {
            if (isset($done[$l->sale_id])) {
                continue;
            }
            $route = $routes[(int) substr($l->code, 4)] ?? null;
            if (! $route) {
                continue;
            }
            $qty = max(1, (int) round((float) $l->quantity));
            $price = (float) $l->total / $qty;
            $line = (object) ['sale_id' => $l->sale_id, 'product_id' => $l->product_id, 'route_id' => $route->id, 'warehouse_id' => $l->warehouse_id];
            $left = $qty;
            foreach ($trips as $t) {
                $stretch = TransportLegs::stretch((int) $t->route_id, (int) $route->origin_id, (int) $route->destination_id);
                if (! $stretch) {
                    continue;
                }
                $leaves = \Carbon\Carbon::parse($t->departure_at)->addMinutes(TransportLegs::path((int) $t->route_id)[$stretch[0]]['minutes']);
                if ($leaves->lte(\Carbon\Carbon::parse($l->created_at))) {
                    continue;
                }
                while ($left > 0 && ($seat = self::takeSeat($state, $t, $stretch[0], $stretch[1]))) {
                    $names = $left === $qty && $l->firstname ? [$l->firstname, (string) $l->lastname] : [$firsts[array_rand($firsts)], $lasts[array_rand($lasts)]];
                    self::insertDemoTicket($cid, $t, $line, $seat, $names, (int) $route->origin_id, (int) $route->destination_id, $price, $l->created_at);
                    $left--;
                    $made++;
                }
                if ($left === 0) {
                    break;
                }
            }
        }

        return $made;
    }

    /**
     * Demo parcels on each demo trip, one in ten fragile, at the route's price per m³. A trip carries 8.5 to 11.5 m³ (user's yes,
     * 2026-11-10: the demo near the client's S/ 250,000 a month, `settings.transport_parcel_month_ref`; was 0.75-2.25 m³ = about
     * 14 %), never over the bus's hold (12 m³ / 2,000 kg on the demo buses). A trip that already has parcels is topped up to its goal.
     */
    private static function demoParcels(int $cid, $trips): int
    {
        // Nothing is received for a bus of next week (2026-11-11: the list showed parcels "received" on future days): only trips
        // leaving up to tomorrow, and the demo's parcels of later days go away (they are made again when their day comes).
        $limit = now()->addDay()->endOfDay();
        $future = DB::table('transport_parcels')->whereIn('id', self::ids($cid, 'transport_parcels'))->where('received_on', '>', $limit->toDateString())->pluck('id')->all();
        if ($future) {
            DB::table('transport_parcels')->whereIn('id', $future)->delete();
            DB::table('demo_records')->where('company_id', $cid)->where('module', self::MODULE)->where('record_type', 'transport_parcels')->whereIn('record_id', $future)->delete();
        }
        $trips = $trips->filter(fn ($t) => \Carbon\Carbon::parse($t->departure_at)->lte($limit))->values();
        $ids = $trips->pluck('id')->all();
        $loaded = DB::table('transport_parcels')->whereIn('trip_id', $ids)->whereNull('deleted_at')->groupBy('trip_id')
            ->selectRaw('trip_id, SUM(volume_m3) m3, SUM(weight_kg) kg')->get()->keyBy('trip_id');
        $hold = DB::table('transport_trips as t')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')->whereIn('t.id', $ids)
            ->selectRaw('t.id, COALESCE(v.cargo_volume_m3, 12) m3, COALESCE(v.cargo_weight_kg, 2000) kg')->get()->keyBy('id');
        $routes = DB::table('transport_routes as r')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->whereIn('r.id', $trips->pluck('route_id')->unique())
            ->get(['r.id', 'r.origin_id', 'r.destination_id', 'r.parcel_rate_m3', 'o.warehouse_id'])->keyBy('id');
        $number = (int) DB::table('transport_parcels')->where('company_id', $cid)->count();
        // Each day near the client's month (2026-11-11: with the full daily plan there are ~17 departures a day, so a fixed
        // 8.5-11.5 m³ a trip would be ten times the client's S/ 250,000): a day's budget is 85 % of the month / 30; what that
        // day's trips already carry counts, the rest is shared among its trips without parcels, at the route's rate
        // (fragile ones pay 3.7x, 1 in 10). A trip that already has parcels keeps them as they are.
        $monthRef = (float) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('transport_parcel_month_ref') ?: 250000;
        $dayBudget = $monthRef * 0.85 / 30;
        $dayOf = fn ($t) => substr((string) $t->departure_at, 0, 10);
        $carried = DB::table('transport_parcels')->whereIn('transport_parcels.trip_id', $ids)->whereNull('transport_parcels.deleted_at')->join('transport_trips as tt', 'tt.id', '=', 'transport_parcels.trip_id')
            ->selectRaw('DATE(tt.departure_at) d, SUM(transport_parcels.price) s')->groupBy('d')->pluck('s', 'd');
        $empty = $trips->reject(fn ($t) => isset($loaded[$t->id]))->countBy($dayOf);
        $names = ['Ana Quispe', 'Luis Flores', 'Rosa García', 'Carlos Rojas', 'Elena Díaz', 'Jorge Torres', 'Lucía Vargas', 'Miguel Ramos'];
        $what = ['Repuestos', 'Ropa', 'Documentos', 'Mercadería', 'Encargo familiar', 'Productos agrícolas'];
        $made = 0;
        foreach ($trips as $t) {
            if (! isset($routes[$t->route_id])) {
                continue;
            }
            $route = $routes[$t->route_id];
            $maxKg = (float) ($hold[$t->id]->kg ?? 2000);
            $holdM3 = (float) ($hold[$t->id]->m3 ?? 12);
            if (isset($loaded[$t->id])) {
                continue;
            }
            $d = $dayOf($t);
            $share = ($dayBudget - (float) ($carried[$d] ?? 0)) / max(1, (int) ($empty[$d] ?? 1));
            if ($share <= 0) {
                continue;
            }
            $rate = max(1, (float) $route->parcel_rate_m3) * 1.27;
            $goal = min($holdM3 * 0.96, max(0.3, $share / $rate * (0.85 + (crc32('parcels'.$t->id) % 31) / 100)));
            $volume = (float) ($loaded[$t->id]->m3 ?? 0);
            $weight = (float) ($loaded[$t->id]->kg ?? 0);
            while ($volume < $goal) {
                [$l, $w, $h] = [random_int(30, 100), random_int(25, 70), random_int(15, 50)];
                $m3 = round($l * $w * $h / 1000000, 4);
                $kg = random_int(2, 25);
                if ($volume + $m3 > $holdM3 ||$weight + $kg > $maxKg) {
                    break;
                }
                $volume += $m3;
                $weight += $kg;
                $fragile = random_int(1, 10) === 1;
                $number++;
                $id = DB::table('transport_parcels')->insertGetId([
                    'company_id' => $cid, 'number' => 'ENC-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT), 'trip_id' => $t->id, 'warehouse_id' => $route->warehouse_id, 'received_on' => substr((string) $t->departure_at, 0, 10),
                    'eta_from' => \Carbon\Carbon::parse($t->departure_at)->addDay()->toDateString(), 'eta_to' => \Carbon\Carbon::parse($t->departure_at)->addDays(3)->toDateString(),
                    'from_location_id' => $route->origin_id, 'to_location_id' => $route->destination_id, 'cargo_type' => $fragile ? 'fragile' : 'general', 'tracking_token' => bin2hex(random_bytes(12)), 'payment' => 'paid',
                    'sender_name' => $names[array_rand($names)], 'receiver_name' => $names[array_rand($names)], 'description' => $what[array_rand($what)], 'pieces' => 1,
                    'length_cm' => $l, 'width_cm' => $w, 'height_cm' => $h, 'weight_kg' => $kg, 'volume_m3' => $m3,
                    'price' => max(10, round($m3 * (float) $route->parcel_rate_m3 * ($fragile ? 3.7 : 1), 2)), 'status' => $t->status === 'completed' ? 'delivered' : 'received',
                    'delivered_to' => $t->status === 'completed' ? $names[array_rand($names)] : null, 'delivered_at' => $t->status === 'completed' ? $t->departure_at : null,
                    'created_at' => $t->departure_at, 'updated_at' => $t->departure_at,
                ]);
                self::tag($cid, 'transport_parcels', $id);
                $made++;
            }
        }

        return $made;
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
        $tickets = DB::table('products')->whereIn('id', self::ids($cid, 'products'))->where('code', 'like', 'PAS-%')->where('code', 'not like', '%-VIP')->whereNull('deleted_at')->get(['id', 'code', 'price']);
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
                    // Its boarding points too (2026-11-11: the full daily plan has them).
                    foreach (DB::table('transport_trip_boardings')->where('trip_id', $t->id)->get() as $b) {
                        $row = (array) $b;
                        unset($row['id']);
                        $row['trip_id'] = $id;
                        $row['board_at'] = \Carbon\Carbon::parse($b->board_at)->addDays(6);
                        $row['created_at'] = $row['updated_at'] = now();
                        DB::table('transport_trip_boardings')->insert($row);
                    }
                    $made++;
                }
            });
        }

        return $made;
    }

    /**
     * A full day of departures for the presentation (user's request, 2026-11-11: the Lima supervisor's Daily departures had
     * 1-2 trips): every day from 14 days back to a week ahead gets the client's own plan -- the departures of its latest
     * real report (times, routes, buses, boarding points CV / P.N. / T.OLIVOS ...), as demo trips. A bus that left Lima one
     * evening leaves Chiclayo the next, so on alternate days the Lima and Chiclayo buses swap. The 2 buses outside the report
     * go Chepén / Trujillo -> Lima one day and back the next. A slot already there (route + time) is skipped; days with the
     * company's own trips are never touched. Passengers come from the demo's ticket sales (tickets(), demoTopUp()).
     */
    public static function schedule(int $cid): int
    {
        if (! self::ids($cid, 'transport_trips')) {
            return 0;
        }
        $demo = array_flip(self::ids($cid, 'transport_trips'));
        // The template: the latest day with the company's own reported departures.
        $tplDay = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('reported_passengers')
            ->whereNotIn('id', array_keys($demo))->max('departure_at');
        if (! $tplDay) {
            return 0;
        }
        $tplDay = substr((string) $tplDay, 0, 10);
        $tpl = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')->where('t.company_id', $cid)->whereNull('t.deleted_at')
            ->whereNotIn('t.id', array_keys($demo))->whereDate('t.departure_at', $tplDay)->orderBy('t.departure_at')
            ->get(['t.id', 't.route_id', 't.vehicle_id', 't.departure_at', 't.arrival_at', 'r.origin_id', 'r.destination_id']);
        if ($tpl->isEmpty()) {
            return 0;
        }
        $boardings = DB::table('transport_trip_boardings')->whereIn('trip_id', $tpl->pluck('id'))->orderBy('seq')->get()->groupBy('trip_id');
        $routes = DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')->where('active', 1)->get(['id', 'origin_id', 'destination_id', 'duration_min']);
        $routeOf = fn ($o, $d) => $routes->first(fn ($r) => (int) $r->origin_id === (int) $o && (int) $r->destination_id === (int) $d);
        $agencies = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtoupper(trim($name)) => (int) $id]);
        // A boarding point by its code in the report (P.N., T.OLIVOS, CHEPEN, TRUX...).
        $pointByLabel = [];
        foreach ($boardings->flatten() as $b) {
            $pointByLabel[mb_strtoupper((string) $b->label)] = (int) $b->location_id;
        }

        // The two directions of the report (e.g. Lima -> Chiclayo and back) and their buses, to swap on alternate days.
        $sides = $tpl->groupBy('origin_id')->keys()->values()->all();
        $busesOf = fn ($origin) => $tpl->where('origin_id', $origin)->pluck('vehicle_id')->values()->all();
        $swap = [];
        if (count($sides) === 2) {
            [$a, $b] = $sides;
            $ba = $busesOf($a);
            $bb = $busesOf($b);
            foreach ($tpl as $t) {
                $mine = (int) $t->origin_id === (int) $a ? $ba : $bb;
                $other = (int) $t->origin_id === (int) $a ? $bb : $ba;
                $i = array_search($t->vehicle_id, $mine, true);
                $swap[$t->id] = ($i !== false && isset($other[$i])) ? $other[$i] : $t->vehicle_id;
            }
        }
        // The buses the report doesn't use: Chepén and Trujillo <-> Lima.
        $used = $tpl->pluck('vehicle_id')->filter()->unique()->all();
        $spare = DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->whereNotIn('id', $used ?: [0])->where(fn ($q) => $q->where('type', 'bus')->orWhereNotNull('seats'))
            ->orderBy('name')->limit(4)->pluck('id')->all();
        // Chepén and Trujillo leave every day, both ways, with two buses each (2026-11-11: Trujillo was missing every other
        // day). The client has 19 buses and 17 are loaded: the missing ones are made as demo buses, like one of theirs.
        $model = $spare ? DB::table('vehicles')->where('id', $spare[0])->first() : null;
        for ($n = count($spare), $num = 390; $model && $n < 4; $n++, $num += 10) {
            $row = (array) $model;
            unset($row['id']);
            $row['name'] = 'Bus '.$num;
            if (array_key_exists('plate_number', $row)) {
                $row['plate_number'] = 'DEM-'.$num;
            }
            $row['created_at'] = $row['updated_at'] = now();
            $id = DB::table('vehicles')->insertGetId($row);
            self::tag($cid, 'vehicles', $id);
            $spare[] = $id;
        }
        $lima = $agencies['LIMA'] ?? null;
        $extra = [];
        foreach ([['CHEPEN', '19:00', '21:00', 'CHEPEN', 'P.N.'], ['TRUJILLO', '21:30', '22:00', 'TRUX', 'T.OLIVOS']] as $k => [$name, $outTime, $backTime, $outCode, $backCode]) {
            $town = $agencies[$name] ?? null;
            foreach ([0, 1] as $phase) {
                $bus = $spare[$k * 2 + $phase] ?? null;
                if ($lima && $town && $bus) {
                    $extra[] = ['bus' => $bus, 'phase' => $phase, 'to_lima' => $routeOf($town, $lima), 'from_lima' => $routeOf($lima, $town), 'out' => $outTime, 'back' => $backTime,
                        'out_point' => $pointByLabel[$outCode] ?? $town, 'out_label' => $outCode, 'back_point' => $pointByLabel[$backCode] ?? $lima, 'back_label' => $backCode];
                }
            }
        }

        $made = 0;
        $tplStart = \Carbon\Carbon::parse($tplDay);
        for ($day = now()->startOfDay()->subDays(self::HISTORY_DAYS); $day->lte(now()->startOfDay()->addDays(7)); $day->addDay()) {
            $d = $day->toDateString();
            if ($d === $tplDay) {
                continue;
            }
            $existing = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('departure_at', $d)->get(['id', 'route_id', 'departure_at', 'reported_passengers']);
            // A day of the client's own report: leave it alone (a departure someone added by hand only takes its slot).
            if ($existing->contains(fn ($x) => ! isset($demo[$x->id]) && $x->reported_passengers !== null)) {
                continue;
            }
            $there = $existing->map(fn ($x) => $x->route_id.'@'.substr((string) $x->departure_at, 11, 5))->flip();
            $odd = abs($tplStart->diffInDays($day)) % 2 === 1;
            $plan = [];
            foreach ($tpl as $t) {
                $plan[] = ['route' => (int) $t->route_id, 'bus' => $odd ? ($swap[$t->id] ?? $t->vehicle_id) : $t->vehicle_id, 'time' => substr((string) $t->departure_at, 11, 5),
                    'minutes' => $t->arrival_at ? \Carbon\Carbon::parse($t->departure_at)->diffInMinutes(\Carbon\Carbon::parse($t->arrival_at)) : null,
                    'points' => collect($boardings[$t->id] ?? [])->map(fn ($b) => [(int) $b->location_id, $b->label, \Carbon\Carbon::parse($t->departure_at)->diffInMinutes(\Carbon\Carbon::parse($b->board_at), false)])->all()];
            }
            foreach ($extra as $e) {
                // Each bus alternates: to Lima one day, back the next; its pair does the opposite.
                $back = $odd !== (bool) $e['phase'];
                $r = $back ? $e['from_lima'] : $e['to_lima'];
                if ($r) {
                    $plan[] = ['route' => (int) $r->id, 'bus' => $e['bus'], 'time' => $back ? $e['back'] : $e['out'], 'minutes' => null,
                        'points' => [[$back ? $e['back_point'] : $e['out_point'], $back ? $e['back_label'] : $e['out_label'], 0]]];
                }
            }
            DB::transaction(function () use ($plan, $there, $d, $routes, $cid, &$made) {
                foreach ($plan as $p) {
                    if (isset($there[$p['route'].'@'.$p['time']])) {
                        continue;
                    }
                    $dep = \Carbon\Carbon::parse($d.' '.$p['time']);
                    $minutes = $p['minutes'] ?: (int) ($routes->firstWhere('id', $p['route'])->duration_min ?? 0) ?: 720;
                    $arr = $dep->copy()->addMinutes($minutes);
                    $id = DB::table('transport_trips')->insertGetId([
                        'company_id' => $cid, 'route_id' => $p['route'], 'vehicle_id' => $p['bus'], 'departure_at' => $dep, 'arrival_at' => $arr,
                        'status' => self::statusAt($dep, $arr, 0), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    self::tag($cid, 'transport_trips', $id);
                    foreach ($p['points'] as $seq => [$loc, $label, $offset]) {
                        DB::table('transport_trip_boardings')->insert(['company_id' => $cid, 'trip_id' => $id, 'location_id' => $loc, 'label' => $label, 'seq' => $seq + 1,
                            'board_at' => $dep->copy()->addMinutes((int) $offset), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $made++;
                }
            });
        }

        return $made;
    }

    /**
     * What the monitoring has on record for the demo (user's request, 2026-11-11: Punctuality was empty): every demo trip
     * that left gets its crew when it has none (2 pilots, 1 hostess, in rotation), its times at each control point (most
     * on time, 1 in 5 a little late, 1 in 12 well late; the delay grows along the way) and, now and then, an incident
     * (traffic and road works on the late ones, a passenger matter, rarely a breakdown). Only demo trips without any
     * time on record; the trips of the company and the live monitoring are never touched.
     */
    public static function control(int $cid): int
    {
        $ids = self::ids($cid, 'transport_trips');
        if (! $ids || ! \Illuminate\Support\Facades\Schema::hasTable('transport_trip_checkpoints')) {
            return 0;
        }
        // The passengers of the buses that left boarded (their pass read up to 25 minutes before), except 1 in 25 who
        // never showed up (2026-11-11: "abordados por unidad").
        DB::table('transport_tickets as x')->join('transport_trips as t', 't.id', '=', 'x.trip_id')
            ->whereIn('x.trip_id', $ids)->whereIn('t.status', ['in_progress', 'completed'])->whereNull('x.boarded_at')->whereRaw('MOD(x.id, 25) <> 0')
            ->update(['x.boarded_at' => DB::raw('DATE_SUB(t.departure_at, INTERVAL MOD(x.id, 25) MINUTE)')]);
        $crewOf = DB::table('transport_trip_crew')->whereIn('trip_id', $ids)->get(['trip_id', 'employee_id', 'role']);
        $pilots = $crewOf->where('role', 'pilot')->pluck('employee_id')->unique()->sort()->values()->all();
        $hostesses = $crewOf->where('role', '!=', 'pilot')->pluck('employee_id')->unique()->sort()->values()->all();
        $hostRole = $crewOf->where('role', '!=', 'pilot')->first()->role ?? 'hostess';
        $userOf = DB::table('employees')->whereIn('id', array_merge($pilots, $hostesses) ?: [0])->pluck('user_id', 'id');
        $hasCrew = $crewOf->pluck('trip_id')->flip();
        $done = DB::table('transport_trip_checkpoints')->whereIn('trip_id', $ids)->distinct()->pluck('trip_id')->flip();
        $trips = DB::table('transport_trips')->whereIn('id', $ids)->whereNull('deleted_at')->where('status', 'completed')
            ->where('departure_at', '>=', now()->subDays(self::HISTORY_DAYS + 1))->where('departure_at', '<=', now())->orderBy('departure_at')->get();
        $notes = [
            'traffic' => ['Tráfico lento en la Panamericana Norte.', 'Congestión a la salida de Lima.', 'Tráfico por obras en la vía.'],
            'road' => ['Vía en reparación, paso alternado.', 'Desvío por obras cerca de Huacho.'],
            'passenger' => ['Pasajero se descompensó, se le atendió a bordo.', 'Pasajero olvidó su equipaje en el terminal.', 'Pasajero subió sin pase, regularizado en el siguiente punto.'],
            'breakdown' => ['Llanta baja, cambiada en ruta.', 'Falla eléctrica menor, revisada en el siguiente terminal.'],
            'other' => ['Control policial de rutina.', 'Parada técnica por lluvia.'],
        ];
        $made = 0;
        foreach ($trips as $n => $t) {
            if (isset($done[$t->id])) {
                continue;
            }
            $seed = crc32('ctl'.$t->id);
            $roll = $seed % 100;
            $base = $roll < 82 ? $seed % 7 : ($roll < 95 ? 11 + $seed % 15 : 26 + $seed % 35); // minutes late at the start
            DB::transaction(function () use ($t, $cid, $n, $pilots, $hostesses, $hostRole, $userOf, $hasCrew, $seed, $base, $notes, &$made) {
                $crewIds = [];
                if (! isset($hasCrew[$t->id]) && count($pilots) >= 2) {
                    $p1 = $pilots[$n % count($pilots)];
                    $p2 = $pilots[($n + 1) % count($pilots)];
                    $rows = [['pilot', $p1], ['pilot', $p2]];
                    if ($hostesses) {
                        $rows[] = [$hostRole, $hostesses[$n % count($hostesses)]];
                    }
                    foreach ($rows as [$role, $emp]) {
                        DB::table('transport_trip_crew')->insert(['trip_id' => $t->id, 'employee_id' => $emp, 'role' => $role]);
                    }
                    $crewIds = [$p1];
                } else {
                    $crewIds = DB::table('transport_trip_crew')->where('trip_id', $t->id)->where('role', 'pilot')->pluck('employee_id')->all();
                }
                $by = $crewIds ? ($userOf[$crewIds[0]] ?? null) : null;
                $points = \App\Services\TransportControl::points($t);
                $delay = $base;
                $last = count($points) - 1;
                foreach ($points as $i => $p) {
                    $arrive = $p['plan_arrive'] ? $p['plan_arrive']->copy()->addMinutes($delay) : null;
                    if ($arrive && $arrive->isFuture()) {
                        break; // still on the way
                    }
                    $delay = max(0, $delay + (int) (crc32('leg'.$t->id.'-'.$i) % 7) - 3); // a few minutes gained or lost per stretch
                    $depart = $p['plan_depart'] && $i < $last ? $p['plan_depart']->copy()->addMinutes($delay) : null;
                    if ($depart && $depart->isFuture()) {
                        $depart = null;
                    }
                    $set = ['company_id' => $cid, 'trip_id' => $t->id, 'location_id' => $p['location_id'], 'created_at' => now(), 'updated_at' => now()];
                    if ($arrive && $i > 0) {
                        $set += ['arrived_at' => $arrive, 'arrived_by' => $by, 'arrive_source' => 'gps', 'arrive_delay_min' => (int) round($p['plan_arrive']->diffInMinutes($arrive, false))];
                    }
                    if ($depart) {
                        $set += ['departed_at' => $depart, 'departed_by' => $by, 'depart_source' => 'gps', 'depart_delay_min' => (int) round($p['plan_depart']->diffInMinutes($depart, false))];
                    }
                    if (count($set) > 5) {
                        DB::table('transport_trip_checkpoints')->insert($set);
                    }
                }
                // An incident now and then: the late trips say why.
                $kind = null;
                if ($base >= 26) {
                    $kind = $seed % 3 === 0 ? 'road' : 'traffic';
                } elseif ($seed % 14 === 0) {
                    $kind = ['passenger', 'passenger', 'other', 'traffic', 'breakdown'][$seed % 5];
                }
                if ($kind) {
                    $at = \Carbon\Carbon::parse($t->departure_at)->addMinutes(30 + $seed % 240);
                    $at = $at->isFuture() ? now()->subMinutes(5) : $at;
                    $list = $notes[$kind];
                    self::tag($cid, 'transport_trip_reports', DB::table('transport_trip_reports')->insertGetId([
                        'company_id' => $cid, 'trip_id' => $t->id, 'user_id' => $by, 'kind' => $kind, 'note' => $list[$seed % count($list)],
                        'created_at' => $at, 'updated_at' => $at,
                    ]));
                }
                $made++;
            });
        }

        return $made;
    }

    /**
     * The services each agency pays every month, for the "Gastos" tab (user's request, 2026-11-11): rent, utilities and
     * internet on the 5th, at each agency's location; the fleet's insurance and the advertising at Lima. Amounts vary a
     * little month to month. Accounting -> Expenses rows tagged as demo, one per category, location and month.
     */
    public static function services(int $cid): int
    {
        if (! self::ids($cid, 'transport_trips')) {
            return 0;
        }
        $cats = DB::table('expense_categories')->where('company_id', $cid)->whereNull('deleted_at')
            ->whereIn('name', ['Office rent', 'Utilities', 'Internet and telecommunications', 'Insurance', 'Advertising'])->pluck('id', 'name');
        $agencies = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->whereNotNull('warehouse_id')
            ->pluck('warehouse_id', 'name')->mapWithKeys(fn ($w, $n) => [mb_strtoupper(trim($n)) => (int) $w]);
        $user = DB::table('users')->where('company_id', $cid)->where('type', 'admin')->whereNull('deleted_at')->orderBy('id')->value('id');
        if ($cats->isEmpty() || $agencies->isEmpty() || ! $user) {
            return 0;
        }
        $lima = $agencies['LIMA'] ?? $agencies->first();
        $plan = []; // [category, warehouse, base amount, detail]
        foreach ($agencies as $name => $wid) {
            $big = $wid === $lima;
            $plan[] = ['Office rent', $wid, $big ? 3500 : 2000, 'Alquiler del local · '.$name];
            $plan[] = ['Utilities', $wid, $big ? 520 : 340, 'Luz y agua · '.$name];
            $plan[] = ['Internet and telecommunications', $wid, $big ? 260 : 180, 'Internet y teléfono · '.$name];
        }
        $plan[] = ['Insurance', $lima, 4800, 'SOAT y seguro de la flota'];
        $plan[] = ['Advertising', $lima, 1200, 'Publicidad en redes y radio'];

        $have = DB::table('expenses')->whereIn('id', self::ids($cid, 'expenses'))->get(['expense_category_id', 'warehouse_id', 'date'])
            ->map(fn ($x) => $x->expense_category_id.'@'.$x->warehouse_id.'@'.substr((string) $x->date, 0, 7))->flip();
        $made = 0;
        for ($m = now()->subDays(self::HISTORY_DAYS)->startOfMonth(); $m->lte(now()); $m->addMonth()) {
            $day = $m->copy()->day(5);
            if ($day->isFuture()) {
                break;
            }
            foreach ($plan as [$cat, $wid, $amount, $detail]) {
                $catId = $cats[$cat] ?? null;
                if (! $catId || isset($have[$catId.'@'.$wid.'@'.$m->format('Y-m')])) {
                    continue;
                }
                $value = round($amount * (0.92 + (crc32($cat.$wid.$m->format('Ym')) % 17) / 100), 2);
                self::tag($cid, 'expenses', DB::table('expenses')->insertGetId([
                    'date' => $day->toDateString(), 'Ref' => 'GS-'.$m->format('ym').'-'.$catId.'-'.$wid, 'user_id' => $user, 'expense_category_id' => $catId,
                    'warehouse_id' => $wid, 'details' => $detail, 'amount' => $value, 'payment_method_id' => 2, 'created_at' => now(), 'updated_at' => now(),
                ]));
                $made++;
            }
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
        $old = DB::table('transport_trips')->whereIn('id', $ids)->where('departure_at', '<', now()->startOfDay()->subDays(self::HISTORY_DAYS + 2))->pluck('id')->all();
        if (! $old) {
            return 0;
        }
        DB::transaction(function () use ($old, $cid) {
            DB::table('transport_trip_crew')->whereIn('trip_id', $old)->delete();
            DB::table('transport_trip_boardings')->whereIn('trip_id', $old)->delete();
            DB::table('transport_trip_checkpoints')->whereIn('trip_id', $old)->delete();
            DB::table('transport_trip_reports')->whereIn('trip_id', $old)->delete();
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
