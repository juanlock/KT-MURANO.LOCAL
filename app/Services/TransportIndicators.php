<?php

namespace App\Services;

use App\Http\Controllers\FleetVehicleController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's "Indicators" tab (Turismo Cautivo, 2026-11-05): profitability and risks, for charts. Every money
 * figure comes from TransportProfit, so the tab and "Profit by route" can never disagree. Only trips already run count;
 * a trip still to leave is not a result yet. The thresholds that turn a figure into a warning are ours, not the owner's:
 * they are in RISK and shown next to each risk.
 */
class TransportIndicators
{
    /** warn / bad limits, in %. */
    public const RISK = [
        'margin' => ['warn' => 10, 'bad' => 0],       // profit over income, below these
        'loss_trips' => ['warn' => 25, 'bad' => 50],  // trips that lost money, from these
        'coverage' => ['warn' => 110, 'bad' => 100],  // tickets sold per trip over the tickets it needs, below these
        'concentration' => ['warn' => 50],            // income from a single route, from this
        'estimated' => ['warn' => 50],                // trips with fuel or tolls not recorded, from this
    ];

    /** The share of a cost that a 10 % change moves. */
    private const CHANGE = 0.10;

    /** @param array<int>|null $warehouses the user's locations (null = all) */
    public static function compute(int $companyId, string $from, string $to, ?array $warehouses): array
    {
        $profit = TransportProfit::compute($companyId, $from, $to, $warehouses);
        $t = $profit['totals'];
        $run = collect($profit['routes'])->flatMap(fn ($r) => $r['trips'])->where('result', '!=', 'pending')->values();

        $routes = collect($profit['routes'])->where('trips_run', '>', 0)->map(function ($r) {
            $trips = collect($r['trips'])->where('result', '!=', 'pending');
            $priced = $trips->whereNotNull('break_even_seats');

            return [
                'route' => $r['route'], 'trips' => $r['trips_run'], 'gain_trips' => $r['gain_trips'], 'loss_trips' => $r['loss_trips'],
                'income' => $r['income'], 'profit' => $r['profit'],
                // Same trips on both sides: those whose break-even is known.
                'sold' => $priced->count() ? round($priced->avg('sold'), 1) : null,
                'needed' => $priced->count() ? round($priced->avg('break_even_seats'), 1) : null,
                'seats' => $priced->count() ? (int) round($priced->avg('seats')) : null,
            ];
        })->values();

        $fleet = self::fleet($companyId, $warehouses);

        return [
            'enabled' => true, 'from' => $profit['from'], 'to' => $profit['to'],
            'totals' => $t,
            'margin' => $t['income'] > 0 ? round($t['profit'] / $t['income'] * 100, 1) : null,
            // Costs of days with no departure: in the total profit, but on no trip, so on no point of the trend.
            'idle_costs' => round($t['payroll_idle'] + $t['agency_idle'], 2),
            'trend' => self::trend($run, $from, $to),
            'routes' => $routes->all(),
            'costs' => collect([
                'fuel' => $t['fuel'], 'payroll' => $t['payroll'], 'staff' => $t['staff'], 'depreciation' => $t['depreciation'], 'maintenance' => $t['maintenance'],
                'tolls' => $t['tolls'], 'agency' => $t['agency'], 'meals' => $t['meals'],
            ])->map(fn ($v, $k) => ['key' => $k, 'amount' => $v])->where('amount', '>', 0)->sortByDesc('amount')->values()->all(),
            'sensitivity' => collect([
                'tickets' => ($t['tickets_income'] - $t['meals']) * self::CHANGE,
                'parcels' => $t['parcels_income'] * self::CHANGE,
                'fuel' => $t['fuel'] * self::CHANGE,
                'payroll' => ($t['payroll'] + $t['staff']) * self::CHANGE,
                'maintenance' => $t['maintenance'] * self::CHANGE,
                'tolls' => $t['tolls'] * self::CHANGE,
            ])->map(fn ($v, $k) => ['key' => $k, 'amount' => round($v, 2)])->where('amount', '>', 0)->sortByDesc('amount')->values()->all(),
            'fleet' => $fleet,
            'risks' => self::risks($t, $run, $routes, $fleet, $profit['missing']),
            'limits' => self::RISK,
        ];
    }

    /** Income and costs by day, week or month, by how long the period is. */
    private static function trend($run, string $from, string $to): array
    {
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to));
        $unit = $days <= 31 ? 'day' : ($days <= 184 ? 'week' : 'month');
        $key = fn ($r) => match ($unit) {
            'day' => substr($r['departure_at'], 0, 10),
            'week' => Carbon::parse($r['departure_at'])->startOfWeek()->toDateString(),
            default => substr($r['departure_at'], 0, 7).'-01',
        };

        return [
            'unit' => $unit,
            'points' => $run->groupBy($key)->sortKeys()->map(fn ($g, $k) => [
                'date' => $k,
                'income' => round($g->sum(fn ($r) => $r['income']['total']), 2),
                'costs' => round($g->sum(fn ($r) => $r['costs']['total']), 2),
            ])->values()->all(),
        ];
    }

    /** Per bus: papers, services and planned maintenance overdue, or falling due soon. Not tied to the dates: it is today's state. */
    private static function fleet(int $companyId, ?array $warehouses): array
    {
        $buses = DB::table('vehicles')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($warehouses !== null, fn ($q) => $q->where(fn ($x) => $x->whereNull('warehouse_id')->orWhereIn('warehouse_id', $warehouses)))->pluck('id')->all();
        $today = Carbon::today();
        $rows = [];
        $add = function (string $bus, bool $overdue) use (&$rows) {
            $rows[$bus] ??= ['bus' => $bus, 'overdue' => 0, 'soon' => 0];
            $rows[$bus][$overdue ? 'overdue' : 'soon']++;
        };
        // The alerts already hold the maintenance plan.
        foreach (app(FleetVehicleController::class)->alerts($today, $today->copy()->addDays(30), $buses) as $a) {
            $add((string) $a['vehicle_name'], $a['days'] < 0);
        }

        return collect($rows)->sortByDesc(fn ($r) => $r['overdue'] * 1000 + $r['soon'])->values()->all();
    }

    private static function risks(array $t, $run, $routes, array $fleet, array $missing): array
    {
        $trips = $run->count();
        $L = self::RISK;
        $risks = [];

        if ($t['income'] > 0) {
            $v = round($t['profit'] / $t['income'] * 100, 1);
            $risks[] = ['key' => 'margin', 'value' => $v, 'level' => $v < $L['margin']['bad'] ? 'bad' : ($v < $L['margin']['warn'] ? 'warn' : 'ok')];
        }
        if ($trips) {
            $v = (int) round($t['loss_trips'] / $trips * 100);
            $risks[] = ['key' => 'loss_trips', 'value' => $v, 'level' => $v >= $L['loss_trips']['bad'] ? 'bad' : ($v >= $L['loss_trips']['warn'] ? 'warn' : 'ok')];
        }
        $priced = $run->whereNotNull('break_even_seats');
        if ($priced->sum('break_even_seats') > 0) {
            $sold = round($priced->avg('sold'), 1);
            $needed = round($priced->avg('break_even_seats'), 1);
            $v = (int) round($priced->avg('sold') / $priced->avg('break_even_seats') * 100);
            $risks[] = ['key' => 'coverage', 'value' => $v, 'sold' => $sold, 'needed' => $needed, 'level' => $v < $L['coverage']['bad'] ? 'bad' : ($v < $L['coverage']['warn'] ? 'warn' : 'ok')];
        }
        if ($t['income'] > 0 && $routes->count()) {
            $top = $routes->sortByDesc('income')->first();
            $v = (int) round($top['income'] / $t['income'] * 100);
            $risks[] = ['key' => 'concentration', 'value' => $v, 'route' => $top['route'], 'level' => $v >= $L['concentration']['warn'] ? 'warn' : 'ok'];
        }
        $overdue = array_sum(array_column($fleet, 'overdue'));
        $soon = array_sum(array_column($fleet, 'soon'));
        $risks[] = ['key' => 'fleet', 'overdue' => $overdue, 'soon' => $soon, 'level' => $overdue ? 'bad' : ($soon ? 'warn' : 'ok')];
        if ($trips) {
            $v = (int) round($run->filter(fn ($r) => ! empty($r['estimated']))->count() / $trips * 100);
            $gaps = count(array_filter($missing));
            $risks[] = ['key' => 'estimated', 'value' => $v, 'gaps' => $gaps, 'level' => ($v >= $L['estimated']['warn'] || $gaps) ? 'warn' : 'ok'];
        }

        // The worst first.
        return collect($risks)->sortBy(fn ($r) => ['bad' => 0, 'warn' => 1, 'ok' => 2][$r['level']])->values()->all();
    }
}
