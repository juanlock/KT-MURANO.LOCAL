<?php

namespace App\Services;

use App\Support\PlaceCoordinates;
use App\Support\TransportLegs;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Was each trip worth it? (Turismo Cautivo's owner, 2026-11-04.)
 *   profit = tickets + parcels - crew pay - payroll - fuel - maintenance - depreciation - tolls - agency - meals
 * Meals = the snack and drink of every deluxe (VIP) ticket sold; regular seats get none (settings.transport_meal_cost; Turismo S/ 10; user's rule, 2026-11-05).
 * Payroll = the monthly salaries of the pilots, hostesses and administrative staff (employees.basic_salary) / 30, shared
 * among each day's trips; a day with no trip counts in the totals only ("payroll_idle").
 * Agency = its cost per day (transport_locations.daily_cost) shared among the trips leaving from it
 * that day; a day with no departure counts in the totals only ("agency_idle").
 * A trip has stops (TransportLegs): every ticket on it counts, whatever its stretch, against one set of costs; seats sold =
 * passenger-km / seat-km, since a seat emptied at a stop is sold again.
 * Maintenance and depreciation go by kilometer (user's OK, 2026-11-04): the bus's cost per km
 * x the route's km, so a long trip carries more and one big repair doesn't sink one trip.
 * Fuel: the trip's real figure, else the bus's cost per km (flagged "estimated"). Tolls: the
 * trip's own, else the route's expected cost (flagged too). A missing figure counts as 0
 * and is listed in `missing`, so the panel never passes a guess off as a fact.
 * A trip from the client's own report has no tickets: its reported_passengers / reported_income count instead, with a whole seat per passenger.
 * Cancelled trips are left out; a trip still to leave is "pending", not a loss: its seats
 * are still selling, and it stays out of the totals.
 */
class TransportProfit
{
    private const RATE_WINDOW_DAYS = 365;

    /** @param array<int>|null $warehouses the user's locations (null = all) */
    public static function compute(int $companyId, string $from, string $to, ?array $warehouses): array
    {
        $trips = DB::table('transport_trips as t')
            ->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')
            ->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $companyId)->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')
            ->whereBetween('t.departure_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($warehouses !== null, fn ($q) => $q->whereIn('o.warehouse_id', $warehouses))
            ->orderBy('t.departure_at')
            ->get(['t.id', 't.route_id', 't.vehicle_id', 't.departure_at', 't.status', 't.fuel_cost', 't.reported_passengers', 't.reported_income', 'r.toll_cost', 'r.distance_km', 'r.origin_id', 'o.daily_cost',
                'o.name as origin', 'd.name as destination', 'o.lat as olat', 'o.lng as olng', 'd.lat as dlat', 'd.lng as dlng',
                'v.name as vehicle', 'v.seats', 'v.purchase_price', 'v.residual_value', 'v.useful_life_km']);
        $ids = $trips->pluck('id')->all();

        $crew = DB::table('transport_trip_crew')->whereIn('trip_id', $ids)->selectRaw('trip_id, SUM(COALESCE(trip_rate, 0)) pay')->groupBy('trip_id')->pluck('pay', 'trip_id');
        $tickets = DB::table('transport_tickets')->whereIn('trip_id', $ids)->get(['trip_id', 'from_location_id', 'to_location_id', 'price', 'is_vip'])->groupBy('trip_id');
        $places = DB::table('transport_locations')->where('company_id', $companyId)->pluck('name', 'id');
        $legTotals = [];
        $parcels = DB::table('transport_parcels')->whereIn('trip_id', $ids)->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->selectRaw('trip_id, COUNT(*) n, SUM(price) income')->groupBy('trip_id')->get()->keyBy('trip_id');
        $tolls = DB::table('transport_tolls')->whereIn('trip_id', $ids)->whereNull('deleted_at')->selectRaw('trip_id, SUM(amount) paid')->groupBy('trip_id')->pluck('paid', 'trip_id');
        $rates = self::busRates($companyId, $trips->pluck('vehicle_id')->unique()->all());
        $fares = self::fares($companyId);
        // Trips leaving from each agency each day: they share its daily cost.
        $perDay = $trips->groupBy(fn ($t) => $t->origin_id.'|'.substr((string) $t->departure_at, 0, 10))->map->count();
        // The salaries of the crew and the ticket offices per day, shared among all of that day's trips of the company.
        $dailyPayroll = (float) DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('staff_type', ['pilot', 'hostess', 'administrative', 'maintenance'])
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', now()->toDateString()))->sum('basic_salary') / 30;
        $tripsOfDay = DB::table('transport_trips')->where('company_id', $companyId)->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->whereBetween('departure_at', [$from.' 00:00:00', $to.' 23:59:59'])->selectRaw('DATE(departure_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');

        $mealCost = (float) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('transport_meal_cost');

        $missing = ['seats' => [], 'depreciation' => [], 'fuel' => [], 'tolls' => [], 'distance' => []];
        $rows = [];
        foreach ($trips as $t) {
            $km = self::km($t);
            $rate = $rates[$t->vehicle_id] ?? ['fuel' => null, 'maintenance' => null];
            $depPerKm = ($t->useful_life_km && $t->purchase_price !== null)
                ? max(0, (float) $t->purchase_price - (float) $t->residual_value) / (int) $t->useful_life_km : null;

            $fuelEstimated = $t->fuel_cost === null;
            $fuel = $fuelEstimated ? (($rate['fuel'] !== null && $km) ? $rate['fuel'] * $km : null) : (float) $t->fuel_cost;
            $tollsEstimated = ! isset($tolls[$t->id]);
            $toll = $tollsEstimated ? ($t->toll_cost !== null ? (float) $t->toll_cost : null) : (float) $tolls[$t->id];
            $maintenance = ($rate['maintenance'] !== null && $km) ? $rate['maintenance'] * $km : 0.0;
            $depreciation = ($depPerKm !== null && $km) ? $depPerKm * $km : null;

            $bus = (string) $t->vehicle;
            $route = $t->origin.' → '.$t->destination;
            if (! $t->seats) { $missing['seats'][$bus] = true; }
            if ($depreciation === null && $km) { $missing['depreciation'][$bus] = true; }
            if ($fuel === null) { $missing['fuel'][$bus] = true; }
            if ($toll === null) { $missing['tolls'][$route] = true; }
            if (! $km) { $missing['distance'][$route] = true; }

            // Its tickets, stretch by stretch.
            $sold = 0;
            $vip = 0;
            $ticketIncome = 0.0;
            $paxKm = 0.0;
            $legs = [];
            $path = TransportLegs::path((int) $t->route_id);
            foreach ($tickets[$t->id] ?? [] as $x) {
                [$a, $b] = TransportLegs::ticketStretch((int) $t->route_id, $x);
                $sold++;
                $vip += $x->is_vip ? 1 : 0;
                $ticketIncome += (float) $x->price;
                $paxKm += max(0, (float) ($path[$b]['km'] ?? 0) - (float) ($path[$a]['km'] ?? 0));
                $label = ($places[$path[$a]['location_id']] ?? '').' → '.($places[$path[$b]['location_id']] ?? '');
                $legs[$label] = ['leg' => $label, 'tickets' => ($legs[$label]['tickets'] ?? 0) + 1, 'income' => round(($legs[$label]['income'] ?? 0) + (float) $x->price, 2)];
            }
            if ($t->reported_passengers !== null) {
                $sold = (int) $t->reported_passengers;
                $ticketIncome = (float) $t->reported_income;
                $paxKm = $sold * (float) $km;
            }
            if ($t->status !== 'scheduled') {
                foreach ($legs as $label => $l) {
                    $legTotals[$label] = ['leg' => $label, 'tickets' => ($legTotals[$label]['tickets'] ?? 0) + $l['tickets'], 'income' => round(($legTotals[$label]['income'] ?? 0) + $l['income'], 2)];
                }
            }
            $seatKm = $t->seats && $km ? (int) $t->seats * $km : 0.0;
            $income = ['tickets' => $ticketIncome, 'parcels' => (float) ($parcels[$t->id]->income ?? 0)];
            $costs = ['staff' => (float) ($crew[$t->id] ?? 0), 'payroll' => $dailyPayroll / max(1, (int) ($tripsOfDay[substr((string) $t->departure_at, 0, 10)] ?? 1)), 'fuel' => (float) $fuel, 'maintenance' => $maintenance, 'depreciation' => (float) $depreciation, 'tolls' => (float) $toll,
                'agency' => (float) $t->daily_cost / max(1, $perDay[$t->origin_id.'|'.substr((string) $t->departure_at, 0, 10)] ?? 1), 'meals' => $vip * $mealCost];
            $totalIncome = array_sum($income);
            $totalCost = array_sum($costs);
            $fare = $sold ? $income['tickets'] / $sold : ($fares[$t->route_id] ?? null);
            // What a ticket leaves on this trip's mix: the meal only goes with the deluxe ones.
            $mealPerTicket = $sold ? $costs['meals'] / $sold : 0.0;

            $rows[] = [
                'id' => (int) $t->id, 'route_id' => (int) $t->route_id, 'route' => $route, 'departure_at' => $t->departure_at, 'status' => $t->status,
                'vehicle' => $bus, 'km' => $km ? round($km) : null, 'seats' => $t->seats ? (int) $t->seats : null, 'sold' => $sold,
                'occupancy' => $seatKm > 0 ? (int) round($paxKm / $seatKm * 100) : null, 'pax_km' => round($paxKm), 'seat_km' => round($seatKm), 'vip' => $vip, 'legs' => array_values($legs),
                // Seats it takes to cover the costs, parcels counted; a deluxe seat also brings its meal.
                'break_even_seats' => $fare && $fare > $mealPerTicket ? (int) ceil(max(0, $totalCost - $costs['meals'] - $income['parcels']) / ($fare - $mealPerTicket)) : null,
                'parcels' => (int) ($parcels[$t->id]->n ?? 0),
                'income' => array_map(fn ($v) => round($v, 2), $income) + ['total' => round($totalIncome, 2)],
                'costs' => array_map(fn ($v) => round($v, 2), $costs) + ['total' => round($totalCost, 2)],
                'estimated' => array_values(array_filter([$fuelEstimated && $fuel !== null ? 'fuel' : null, $tollsEstimated && $toll !== null ? 'tolls' : null])),
                'profit' => round($totalIncome - $totalCost, 2),
                'result' => $t->status === 'scheduled' ? 'pending' : ($totalIncome - $totalCost >= 0 ? 'gain' : 'loss'),
            ];
        }

        $done = collect($rows)->where('result', '!=', 'pending');
        $routes = collect($rows)->groupBy('route_id')->map(function ($g) {
            $run = $g->where('result', '!=', 'pending');
            $seats = $run->sum('seat_km');

            return [
                'route_id' => $g->first()['route_id'], 'route' => $g->first()['route'],
                'trips_run' => $run->count(), 'trips_pending' => $g->count() - $run->count(),
                'gain_trips' => $run->where('result', 'gain')->count(), 'loss_trips' => $run->where('result', 'loss')->count(),
                'occupancy' => $seats ? (int) round($run->sum('pax_km') / $seats * 100) : null,
                'income' => round($run->sum(fn ($r) => $r['income']['total']), 2), 'costs' => round($run->sum(fn ($r) => $r['costs']['total']), 2),
                'profit' => round($run->sum('profit'), 2),
                'trips' => $g->sortByDesc('departure_at')->values()->all(),
            ];
        })->sortBy('profit')->values()->all();
        $seats = $done->sum('seat_km');
        $sumOf = fn (string $side, string $key) => round($done->sum(fn ($r) => $r[$side][$key]), 2);
        $idle = self::agencyIdle($companyId, $from, $to, $warehouses, $perDay->keys()->all());
        $payrollIdle = $warehouses === null ? self::payrollIdle($companyId, $from, $to, $dailyPayroll, $tripsOfDay->keys()->all()) : 0.0;

        return [
            'enabled' => true, 'from' => $from, 'to' => $to,
            'totals' => [
                'income' => $sumOf('income', 'total'), 'tickets_income' => $sumOf('income', 'tickets'), 'parcels_income' => $sumOf('income', 'parcels'),
                'costs' => round($sumOf('costs', 'total') + $idle + $payrollIdle, 2), 'staff' => $sumOf('costs', 'staff'),
                'payroll' => round($sumOf('costs', 'payroll') + $payrollIdle, 2), 'payroll_idle' => $payrollIdle, 'fuel' => $sumOf('costs', 'fuel'),
                'maintenance' => $sumOf('costs', 'maintenance'), 'depreciation' => $sumOf('costs', 'depreciation'), 'tolls' => $sumOf('costs', 'tolls'),
                'agency' => round($sumOf('costs', 'agency') + $idle, 2), 'agency_idle' => $idle, 'meals' => $sumOf('costs', 'meals'),
                'profit' => round($done->sum('profit') - $idle - $payrollIdle, 2),
                'trips_run' => $done->count(), 'trips_pending' => count($rows) - $done->count(),
                'gain_trips' => $done->where('result', 'gain')->count(), 'loss_trips' => $done->where('result', 'loss')->count(),
                'occupancy' => $seats ? (int) round($done->sum('pax_km') / $seats * 100) : null,
                'tickets' => $done->sum('sold'), 'vip' => $done->sum('vip'),
            ],
            'legs' => collect($legTotals)->sortByDesc('income')->values()->all(),
            'routes' => $routes,
            'missing' => array_map(fn ($m) => array_keys($m), $missing),
        ];
    }

    /** The salaries of the days with no trip at all, from the company's first trip up to today. */
    private static function payrollIdle(int $companyId, string $from, string $to, float $daily, array $busyDays): float
    {
        $first = DB::table('transport_trips')->where('company_id', $companyId)->whereNull('deleted_at')->min('departure_at');
        if (! $first || $daily <= 0) {
            return 0.0;
        }
        $busy = array_flip($busyDays);
        $total = 0.0;
        $end = Carbon::parse(min($to, Carbon::today()->toDateString()));
        for ($day = Carbon::parse(max($from, substr((string) $first, 0, 10))); $day->lte($end); $day->addDay()) {
            if (! isset($busy[$day->toDateString()])) {
                $total += $daily;
            }
        }

        return round($total, 2);
    }

    /**
     * What the agencies cost on the days nothing left from them. Counted from the company's first
     * trip, up to today: a period before it started, or still to come, costs nothing.
     */
    private static function agencyIdle(int $companyId, string $from, string $to, ?array $warehouses, array $busyDays): float
    {
        $first = DB::table('transport_trips')->where('company_id', $companyId)->whereNull('deleted_at')->min('departure_at');
        if (! $first) {
            return 0.0;
        }
        $start = Carbon::parse(max($from, substr((string) $first, 0, 10)));
        $end = Carbon::parse(min($to, Carbon::today()->toDateString()));
        $busy = array_flip($busyDays);
        $total = 0.0;
        $agencies = DB::table('transport_locations')->where('company_id', $companyId)->whereNull('deleted_at')->where('kind', 'agency')->where('active', 1)->where('daily_cost', '>', 0)
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses))->get(['id', 'daily_cost']);
        foreach ($agencies as $a) {
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                if (! isset($busy[$a->id.'|'.$day->toDateString()])) {
                    $total += (float) $a->daily_cost;
                }
            }
        }

        return round($total, 2);
    }

    /** The route's km: typed, else the map's straight line + 25 % (as the board). */
    private static function km(object $t): ?float
    {
        if ($t->distance_km !== null && (float) $t->distance_km > 0) {
            return (float) $t->distance_km;
        }
        if ($t->olat !== null && $t->dlat !== null) {
            return PlaceCoordinates::km((float) $t->olat, (float) $t->olng, (float) $t->dlat, (float) $t->dlng) * 1.25;
        }

        return null;
    }

    /** vehicle id => cost per km of fuel and of maintenance, over the km its finished trips ran in the last year. */
    private static function busRates(int $companyId, array $vehicleIds): array
    {
        $since = Carbon::today()->subDays(self::RATE_WINDOW_DAYS)->toDateString();
        $today = Carbon::today()->toDateString();
        $done = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->where('t.company_id', $companyId)->whereIn('t.vehicle_id', $vehicleIds)->whereNull('t.deleted_at')->where('t.status', 'completed')
            ->where('t.departure_at', '>=', $since)
            ->get(['t.vehicle_id', 't.fuel_cost', 'r.distance_km', 'o.lat as olat', 'o.lng as olng', 'd.lat as dlat', 'd.lng as dlng']);
        $kms = [];
        $realFuel = []; // the real fuel of its trips, and the km of those trips
        foreach ($done as $t) {
            $km = (float) self::km($t);
            $kms[$t->vehicle_id] = ($kms[$t->vehicle_id] ?? 0) + $km;
            if ($t->fuel_cost !== null && $km > 0) {
                $realFuel[$t->vehicle_id]['cost'] = ($realFuel[$t->vehicle_id]['cost'] ?? 0) + (float) $t->fuel_cost;
                $realFuel[$t->vehicle_id]['km'] = ($realFuel[$t->vehicle_id]['km'] ?? 0) + $km;
            }
        }
        $fuel = DB::table('vehicle_fuel_logs')->whereNull('deleted_at')->whereIn('vehicle_id', $vehicleIds)
            ->whereDate('log_date', '>=', $since)->whereDate('log_date', '<=', $today)->selectRaw('vehicle_id, SUM(total_cost) c')->groupBy('vehicle_id')->pluck('c', 'vehicle_id');
        $maintenance = DB::table('vehicle_maintenances')->whereNull('deleted_at')->whereIn('vehicle_id', $vehicleIds)->whereNotIn('status', ['scheduled', 'cancelled'])
            ->whereDate('service_date', '>=', $since)->whereDate('service_date', '<=', $today)->selectRaw('vehicle_id, SUM(cost) c')->groupBy('vehicle_id')->pluck('c', 'vehicle_id');
        $out = [];
        foreach ($vehicleIds as $id) {
            $km = $kms[$id] ?? 0;
            $out[$id] = [
                // The bus's fuel logs, else what its trips really burned (never both: the same diesel).
                'fuel' => $km > 0 && isset($fuel[$id]) ? (float) $fuel[$id] / $km
                    : (isset($realFuel[$id]) ? $realFuel[$id]['cost'] / $realFuel[$id]['km'] : null),
                'maintenance' => $km > 0 && isset($maintenance[$id]) ? (float) $maintenance[$id] / $km : null,
            ];
        }

        return $out;
    }

    /** route id => the ticket's list price (product PAS-{route}). */
    private static function fares(int $companyId): array
    {
        $out = [];
        foreach (DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where('code', 'like', 'PAS-%')->where('code', 'not like', '%-VIP')->get(['code', 'price']) as $p) {
            if ((float) $p->price > 0) {
                $out[(int) substr($p->code, 4)] = (float) $p->price;
            }
        }

        return $out;
    }
}
