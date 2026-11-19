<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Mando operativo" (Turismo Cautivo, 2026-11-18): avoid the loss before it happens. For every departure still to
 * leave (today .. +21 days) how it is selling, how many passengers it will end with and whether it will cover its costs,
 * and which week marketing should push.
 *
 * Costs are TransportProfit's own, trip by trip (same assumptions, same shares), split into:
 *   variable (saved if the bus does not leave): crew pay per trip, fuel, tolls, maintenance per km, snack and drink
 *     (only when settings.transport_meal_cost > 0) and the sales channel's commission
 *     (settings.transport_channel_commission = {channel: %}, 0 while that column does not exist);
 *   fixed share (paid anyway): payroll's daily share, the agency's daily cost, depreciation.
 * Projection: the booking curve of the route (else of every route) from the trips completed in the last 120 days =
 * the share of a trip's final tickets already sold H hours before it left. Projected = sold / curve(H); when the curve
 * says too little was sold by then (or there is no history), sold + the route's usual final passengers for that weekday
 * x what is still to be sold. Never more than the bus holds.
 * Status: red = the projected income does not pay the variable cost (consider merging / cancelling); amber = pays the
 * variable cost but not the fixed share (it must leave: marketing's target); green = covers everything.
 */
class TransportOutlook
{
    public const HISTORY_DAYS = 120;
    public const CHANNELS = ['agency', 'jelaf', 'perubus', 'phone'];
    public const ONLINE = ['jelaf', 'perubus'];
    /** Next week already selling this well (projected occupancy %) = push this week or the one after (the client's rule). */
    public const NEXT_WEEK_GOOD = 75;
    /** Below this share of the final sales the curve is not trusted on its own. */
    private const MIN_CURVE = 0.15;
    private const MAX_HOURS = 21 * 24 + 23;

    /** @param array<int>|null $warehouses the user's locations (null = all) */
    public static function compute(int $companyId, ?array $warehouses, int $days = 21): array
    {
        $now = Carbon::now();
        $today = Carbon::today();
        $to = $today->copy()->addDays(max(1, $days));

        // TransportProfit's trips and costs over the window (its rows carry every cost of each trip).
        $profit = TransportProfit::compute($companyId, $today->toDateString(), $to->toDateString(), $warehouses);
        $rows = collect($profit['routes'] ?? [])->flatMap(fn ($r) => $r['trips'])
            ->filter(fn ($t) => in_array($t['status'], ['scheduled', 'in_progress'], true))->values();
        $ids = $rows->pluck('id')->all();

        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first();
        $mealCost = (float) ($settings->transport_meal_cost ?? 0);
        $commission = self::commissionRates($settings);
        $hasChannel = Schema::hasColumn('transport_tickets', 'channel');

        // The tickets sold so far on those trips, with their channel.
        $tickets = DB::table('transport_tickets')->whereIn('trip_id', $ids ?: [0])
            ->get(array_merge(['trip_id', 'price', 'is_vip', 'created_at'], $hasChannel ? ['channel'] : []))->groupBy('trip_id');

        $history = self::history($companyId, $warehouses, $today);
        $meta = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->whereIn('t.id', $ids ?: [0])->get(['t.id', 't.reported_passengers', 'r.origin_id'])->keyBy('id');

        $channels = [];
        foreach (array_merge(self::CHANNELS, ['unknown']) as $c) {
            $channels[$c] = ['channel' => $c, 'tickets' => 0, 'income' => 0.0];
        }

        $out = [];
        foreach ($rows as $t) {
            $dep = Carbon::parse($t['departure_at']);
            $hours = max(0, (int) floor($now->diffInMinutes($dep, false) / 60));
            $list = $tickets[$t['id']] ?? collect();
            $sold = (int) $t['sold'];
            $seats = (int) ($t['seats'] ?? 0);
            $ticketIncome = (float) $t['income']['tickets'];

            // Commission of what is sold, channel by channel.
            $comm = 0.0;
            foreach ($list as $x) {
                $ch = $hasChannel && in_array($x->channel ?? null, self::CHANNELS, true) ? $x->channel : 'unknown';
                $channels[$ch]['tickets']++;
                $channels[$ch]['income'] += (float) $x->price;
                $comm += (float) $x->price * ($commission[$ch] ?? 0) / 100;
            }

            // How it will end.
            $routeId = (int) $t['route_id'];
            $weekday = (int) $dep->dayOfWeekIso;
            $usual = $history['avg'][$routeId][$weekday] ?? $history['avgRoute'][$routeId] ?? $history['avgAll'];
            $curve = self::curveAt($history['curve'][$routeId] ?? $history['curveAll'], $hours);
            $final = $t['status'] === 'in_progress' || ($meta[$t['id']]->reported_passengers ?? null) !== null;
            if ($final) {
                [$projected, $method] = [$sold, 'final'];
            } elseif ($curve !== null && $curve >= self::MIN_CURVE && $sold >= 3) {
                [$projected, $method] = [$sold / $curve, 'curve'];
            } else {
                $left = $curve !== null ? max(0, 1 - $curve) : 1;
                [$projected, $method] = [$usual !== null ? max($sold, $sold + $usual * $left) : $sold, $usual !== null ? 'usual' : 'sold'];
            }
            // A seat emptied at a stop is sold again: tickets per seat from what is sold so far (at least one).
            $seatEq = $t['km'] ? (float) $t['pax_km'] / (float) $t['km'] : $sold;
            $perSeat = $sold > 0 && $seatEq > 0 ? max(1, $sold / $seatEq) : 1;
            if ($seats > 0) {
                $projected = min($projected, $seats * $perSeat);
            }
            $projected = (int) round($projected);
            $projOcc = $seats > 0 ? (int) min(100, round($projected / $perSeat / $seats * 100)) : null;

            $fare = $sold > 0 ? $ticketIncome / $sold : ($history['fare'][$routeId] ?? $history['fareAll']);
            $commRate = $ticketIncome > 0 ? $comm / $ticketIncome : $history['commissionRate'];
            $mealPer = $mealCost > 0 ? ($sold > 0 ? (int) $t['vip'] / $sold : $history['vipShare']) * $mealCost : 0.0;
            $projTickets = $projected * (float) $fare;
            $income = $projTickets + (float) $t['income']['parcels'];

            $c = $t['costs'];
            $variable = [
                'staff' => (float) $c['staff'], 'fuel' => (float) $c['fuel'], 'tolls' => (float) $c['tolls'], 'maintenance' => (float) $c['maintenance'],
                'meals' => $projected * $mealPer, 'commission' => $projTickets * $commRate,
            ];
            $fixed = ['payroll' => (float) $c['payroll'], 'agency' => (float) $c['agency'], 'depreciation' => (float) $c['depreciation']];
            $var = array_sum($variable);
            $all = $var + array_sum($fixed);
            $status = $income < $var ? 'red' : ($income < $all ? 'amber' : 'green');
            // Each extra ticket leaves its fare minus its commission and meal.
            $net = (float) $fare * (1 - $commRate) - $mealPer;
            $toBreakEven = $status === 'green' ? 0 : ($net > 0 ? (int) ceil(($all - $income) / $net) : null);
            $toVariable = $status === 'red' ? ($net > 0 ? (int) ceil(($var - $income) / $net) : null) : 0;

            $out[] = [
                'id' => $t['id'], 'route_id' => $routeId, 'route' => $t['route'], 'departure_at' => $t['departure_at'], 'status' => $t['status'],
                'vehicle' => $t['vehicle'], 'seats' => $seats ?: null, 'sold' => $sold, 'vip' => (int) $t['vip'],
                'occupancy' => $t['occupancy'], 'projected' => $projected, 'projected_occupancy' => $projOcc, 'method' => $method,
                'curve' => $curve !== null ? round($curve, 3) : null, 'usual' => $usual !== null ? round($usual, 1) : null, 'hours_left' => $hours,
                'fare' => $fare !== null ? round((float) $fare, 2) : null,
                'income' => ['sold' => round($ticketIncome, 2), 'projected' => round($income, 2), 'parcels' => round((float) $t['income']['parcels'], 2)],
                'costs' => [
                    'variable' => array_map(fn ($v) => round($v, 2), $variable) + ['total' => round($var, 2)],
                    'fixed' => array_map(fn ($v) => round($v, 2), $fixed) + ['total' => round($all - $var, 2)],
                    'total' => round($all, 2),
                ],
                'estimated' => $t['estimated'],
                'projected_profit' => round($income - $all, 2),
                'risk' => $status, 'seats_to_breakeven' => $toBreakEven, 'seats_to_variable' => $toVariable,
                // Projected seat-km, for the weeks' occupancy.
                '_seat_km' => (float) $t['seat_km'], '_proj_pax_km' => $projOcc !== null ? (float) $t['seat_km'] * $projOcc / 100 : 0.0,
            ];
        }

        $order = ['red' => 0, 'amber' => 1, 'green' => 2];
        usort($out, fn ($a, $b) => [$order[$a['risk']], $a['departure_at']] <=> [$order[$b['risk']], $b['departure_at']]);

        $weeks = self::weeks($out, $today);
        $totalTickets = array_sum(array_column($channels, 'tickets'));
        $online = array_sum(array_map(fn ($c) => $channels[$c]['tickets'], self::ONLINE));
        $known = $totalTickets - $channels['unknown']['tickets'];
        $seatKm = array_sum(array_column($out, '_seat_km'));
        $trips = array_map(function ($r) {
            unset($r['_seat_km'], $r['_proj_pax_km']);

            return $r;
        }, $out);

        return [
            'enabled' => true, 'from' => $today->toDateString(), 'to' => $to->toDateString(), 'generated_at' => $now->toDateTimeString(),
            'totals' => [
                'trips' => count($out),
                'red' => count(array_filter($out, fn ($r) => $r['risk'] === 'red')),
                'amber' => count(array_filter($out, fn ($r) => $r['risk'] === 'amber')),
                'green' => count(array_filter($out, fn ($r) => $r['risk'] === 'green')),
                'sold' => array_sum(array_column($out, 'sold')),
                'projected' => array_sum(array_column($out, 'projected')),
                'projected_occupancy' => $seatKm > 0 ? (int) round(array_sum(array_column($out, '_proj_pax_km')) / $seatKm * 100) : null,
                'projected_income' => round(array_sum(array_map(fn ($r) => $r['income']['projected'], $out)), 2),
                'projected_cost' => round(array_sum(array_map(fn ($r) => $r['costs']['total'], $out)), 2),
                'projected_profit' => round(array_sum(array_column($out, 'projected_profit')), 2),
                'seats_missing' => array_sum(array_map(fn ($r) => (int) $r['seats_to_breakeven'], $out)),
                'online_pct' => $known > 0 ? (int) round($online / $known * 100) : null,
            ],
            'weeks' => $weeks,
            'focus' => self::focus($weeks),
            'channels' => array_values(array_map(fn ($c) => $c + ['pct' => $totalTickets ? (int) round($c['tickets'] / $totalTickets * 100) : 0, 'income' => round($c['income'], 2)], $channels)),
            'channel_known' => $hasChannel,
            'commission' => $commission,
            'history' => [
                'days' => self::HISTORY_DAYS, 'trips' => $history['trips'], 'tickets' => $history['tickets'],
                'advance_share' => $history['advanceShare'],
            ],
            'low_data' => $history['trips'] < 10 || $history['advanceShare'] < 5,
            'missing' => $profit['missing'] ?? [],
            'trips' => $trips,
            'money' => true,
        ];
    }

    /**
     * The board without money (user's rule, 2026-11-19, Turismo: Operaciones sees the projection and the red / amber / green, never
     * income, costs or profit -- those are for the Director and the Manager). Removes every amount, keeps counts and statuses.
     */
    public static function withoutMoney(array $r): array
    {
        if (empty($r['enabled'])) {
            return $r;
        }
        unset($r['totals']['projected_income'], $r['totals']['projected_cost'], $r['totals']['projected_profit'], $r['commission']);
        $r['weeks'] = array_map(function ($w) {
            unset($w['projected_profit'], $w['projected_income'], $w['projected_cost']);

            return $w;
        }, $r['weeks'] ?? []);
        $r['channels'] = array_map(function ($c) {
            unset($c['income']);

            return $c;
        }, $r['channels'] ?? []);
        $r['trips'] = array_map(function ($t) {
            unset($t['income'], $t['costs'], $t['projected_profit'], $t['fare']);

            return $t;
        }, $r['trips'] ?? []);
        $r['money'] = false;

        return $r;
    }

    /** {channel: %} from settings.transport_channel_commission (0 everywhere until that column exists). */
    private static function commissionRates(?object $settings): array
    {
        $rates = array_fill_keys(array_merge(self::CHANNELS, ['unknown']), 0.0);
        if ($settings && Schema::hasColumn('settings', 'transport_channel_commission')) {
            $raw = $settings->transport_channel_commission ?? null;
            $json = is_string($raw) ? json_decode($raw, true) : (array) $raw;
            foreach ((array) $json as $k => $v) {
                if (array_key_exists($k, $rates) && is_numeric($v)) {
                    $rates[$k] = max(0.0, min(100.0, (float) $v));
                }
            }
        }

        return $rates;
    }

    /**
     * What the completed trips of the last 120 days teach: the booking curve per route and for all (share of the final
     * tickets sold at least H hours before leaving), the usual final passengers per route and weekday, the average fare.
     */
    private static function history(int $companyId, ?array $warehouses, Carbon $today): array
    {
        $since = $today->copy()->subDays(self::HISTORY_DAYS)->toDateString();
        $base = fn () => DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')
            ->where('t.company_id', $companyId)->whereNull('t.deleted_at')->where('t.status', 'completed')
            ->where('t.departure_at', '>=', $since)->where('t.departure_at', '<', $today->toDateString())
            ->when($warehouses !== null, fn ($q) => $q->whereIn('o.warehouse_id', $warehouses));

        $hoursSql = 'LEAST(GREATEST(TIMESTAMPDIFF(HOUR, k.created_at, t.departure_at), 0), '.self::MAX_HOURS.')';
        $buckets = $base()->join('transport_tickets as k', 'k.trip_id', '=', 't.id')->whereNull('t.reported_passengers')
            ->selectRaw("t.route_id, $hoursSql h, COUNT(*) n, SUM(k.price) amount, SUM(k.is_vip) vip")->groupBy('t.route_id', 'h')->get();
        $byRoute = [];
        $all = [];
        $fareSum = [];
        $fareN = [];
        $vip = 0;
        foreach ($buckets as $b) {
            $byRoute[$b->route_id][(int) $b->h] = ($byRoute[$b->route_id][(int) $b->h] ?? 0) + (int) $b->n;
            $all[(int) $b->h] = ($all[(int) $b->h] ?? 0) + (int) $b->n;
            $fareSum[$b->route_id] = ($fareSum[$b->route_id] ?? 0) + (float) $b->amount;
            $fareN[$b->route_id] = ($fareN[$b->route_id] ?? 0) + (int) $b->n;
            $vip += (int) $b->vip;
        }
        $tickets = array_sum($all);
        $advance = 0;
        foreach ($all as $h => $n) {
            $advance += $h >= 24 ? $n : 0;
        }

        // Final passengers per trip (tickets, or the client's reported figure).
        $finals = $base()->leftJoin('transport_tickets as k', 'k.trip_id', '=', 't.id')
            ->selectRaw('t.id, t.route_id, DAYOFWEEK(t.departure_at) dow, MAX(t.reported_passengers) reported, COUNT(k.id) n')->groupBy('t.id', 't.route_id', 'dow')->get();
        $sum = [];
        $sumRoute = [];
        foreach ($finals as $f) {
            $n = $f->reported !== null ? (int) $f->reported : (int) $f->n;
            $iso = ((int) $f->dow + 5) % 7 + 1; // MySQL 1 = Sunday -> ISO 7
            $sum[$f->route_id][$iso][] = $n;
            $sumRoute[$f->route_id][] = $n;
        }
        $avg = fn (array $l) => count($l) ? array_sum($l) / count($l) : null;
        $allFinals = $finals->map(fn ($f) => $f->reported !== null ? (int) $f->reported : (int) $f->n)->all();

        $fares = [];
        foreach ($fareSum as $r => $s) {
            $fares[$r] = $fareN[$r] ? $s / $fareN[$r] : null;
        }

        return [
            'curve' => array_map(fn ($b) => array_sum($b) >= 30 ? self::cumulative($b) : null, $byRoute),
            'curveAll' => $tickets >= 30 ? self::cumulative($all) : null,
            'avg' => array_map(fn ($w) => array_map($avg, $w), $sum),
            'avgRoute' => array_map($avg, $sumRoute),
            'avgAll' => $avg($allFinals),
            'fare' => $fares,
            'fareAll' => $tickets ? array_sum($fareSum) / $tickets : null,
            'vipShare' => $tickets ? $vip / $tickets : 0.0,
            'commissionRate' => 0.0,
            'trips' => $finals->count(),
            'tickets' => $tickets,
            'advanceShare' => $tickets ? (int) round($advance / $tickets * 100) : 0,
        ];
    }

    /** hours => tickets sold that many hours before  ->  hours => share sold at least that many hours before. */
    private static function cumulative(array $buckets): array
    {
        $total = array_sum($buckets);
        $out = [];
        $acc = 0;
        for ($h = self::MAX_HOURS; $h >= 0; $h--) {
            $acc += $buckets[$h] ?? 0;
            $out[$h] = $acc / $total;
        }

        return $out;
    }

    private static function curveAt(?array $curve, int $hours): ?float
    {
        // A curve that is null for a route falls back to every route's (handled by the caller's ??).
        return $curve === null ? null : ($curve[min($hours, self::MAX_HOURS)] ?? null);
    }

    /** This week (Mon-Sun with today), the next one and the one after. */
    private static function weeks(array $rows, Carbon $today): array
    {
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $start = $today->copy()->startOfWeek(Carbon::MONDAY)->addWeeks($i);
            $end = $start->copy()->addDays(6);
            $in = array_values(array_filter($rows, fn ($r) => substr((string) $r['departure_at'], 0, 10) >= $start->toDateString() && substr((string) $r['departure_at'], 0, 10) <= $end->toDateString()));
            $seatKm = array_sum(array_column($in, '_seat_km'));
            $amber = array_values(array_filter($in, fn ($r) => $r['risk'] === 'amber'));
            $weak = function (callable $key) use ($amber) {
                $g = [];
                foreach ($amber as $r) {
                    $k = $key($r);
                    $g[$k] = ['label' => $k, 'trips' => ($g[$k]['trips'] ?? 0) + 1, 'seats_missing' => ($g[$k]['seats_missing'] ?? 0) + (int) $r['seats_to_breakeven']];
                }
                usort($g, fn ($a, $b) => $b['seats_missing'] <=> $a['seats_missing']);

                return array_slice($g, 0, 3);
            };
            $out[] = [
                'index' => $i, 'from' => $start->toDateString(), 'to' => $end->toDateString(),
                'trips' => count($in),
                'red' => count(array_filter($in, fn ($r) => $r['risk'] === 'red')),
                'amber' => count($amber),
                'green' => count(array_filter($in, fn ($r) => $r['risk'] === 'green')),
                'sold' => array_sum(array_column($in, 'sold')),
                'projected' => array_sum(array_column($in, 'projected')),
                'projected_occupancy' => $seatKm > 0 ? (int) round(array_sum(array_column($in, '_proj_pax_km')) / $seatKm * 100) : null,
                'projected_profit' => round(array_sum(array_column($in, 'projected_profit')), 2),
                'seats_missing' => array_sum(array_map(fn ($r) => (int) $r['seats_to_breakeven'], $in)),
                'amber_seats_missing' => array_sum(array_map(fn ($r) => (int) $r['seats_to_breakeven'], $amber)),
                'weak_routes' => $weak(fn ($r) => $r['route']),
                'weak_days' => $weak(fn ($r) => substr((string) $r['departure_at'], 0, 10)),
            ];
        }

        return $out;
    }

    /**
     * Marketing's focus: the week with the most seats missing on the trips that must leave (amber). When next week is
     * already selling well, it is left out: push this week or the one after (the client's rule).
     */
    private static function focus(array $weeks): ?array
    {
        $nextGood = isset($weeks[1]) && $weeks[1]['projected_occupancy'] !== null && $weeks[1]['projected_occupancy'] >= self::NEXT_WEEK_GOOD;
        $candidates = array_filter($weeks, fn ($w) => $w['amber_seats_missing'] > 0 && ! ($nextGood && $w['index'] === 1));
        if (! $candidates) {
            return null;
        }
        usort($candidates, fn ($a, $b) => $b['amber_seats_missing'] <=> $a['amber_seats_missing']);
        $w = $candidates[0];

        return [
            'week' => $w['index'], 'from' => $w['from'], 'to' => $w['to'], 'seats_missing' => $w['amber_seats_missing'],
            'next_week_good' => $nextGood, 'routes' => $w['weak_routes'], 'days' => $w['weak_days'],
        ];
    }
}
