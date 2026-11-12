<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's "Gastos" tab (Turismo Cautivo, user's request 2026-11-11): what the business spends, by kind, in the
 * period of the dashboard's filter, with its trend and the period before. The trips' costs come from TransportProfit
 * (crew, payroll, fuel, maintenance, depreciation, tolls, agencies, meals) so this tab, "Profit by route" and the owner's
 * 12 months never disagree; the services paid (rent, utilities, internet, insurance...) come from Accounting -> Expenses
 * of the agencies' locations (an expense made by a payroll is left out: the payroll is already counted).
 */
class TransportExpenses
{
    /** The trips' cost keys (TransportProfit) and their names. */
    public const KINDS = [
        'staff' => ['Crew', 'Tripulación'], 'payroll' => ['Payroll', 'Planilla'], 'fuel' => ['Fuel', 'Combustible'],
        'maintenance' => ['Maintenance', 'Mantenimiento'], 'depreciation' => ['Depreciation', 'Depreciación'], 'tolls' => ['Tolls', 'Peajes'],
        'agency' => ['Agencies (daily cost)', 'Agencias (costo diario)'], 'meals' => ['VIP meals', 'Refrigerios VIP'],
    ];

    /** Spanish names of the usual expense categories (expense_categories hold English names). */
    private const CATEGORY_ES = [
        'Office rent' => 'Alquiler de locales', 'Utilities' => 'Luz y agua', 'Internet and telecommunications' => 'Internet y telefonía',
        'Insurance' => 'Seguros', 'Advertising' => 'Publicidad', 'Marketing' => 'Marketing', 'Professional services' => 'Servicios profesionales',
        'Accounting services' => 'Contabilidad', 'Legal services' => 'Servicios legales', 'Repairs and maintenance' => 'Reparaciones',
        'Office supplies' => 'Útiles de oficina', 'Bank fees' => 'Comisiones bancarias', 'Taxes and government fees' => 'Impuestos y tasas',
        'Software subscriptions' => 'Software', 'Uniforms and protective equipment' => 'Uniformes', 'Miscellaneous expenses' => 'Otros gastos',
        'Travel' => 'Viajes', 'Meals and entertainment' => 'Comidas y atenciones', 'Business licenses and permits' => 'Licencias y permisos',
    ];

    /** @param array<int>|null $warehouses the user's locations (null = all) */
    public static function compute(int $companyId, string $from, string $to, ?array $warehouses, bool $es = true): array
    {
        $now = self::period($companyId, $from, $to, $warehouses, $es);
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $prev = self::period($companyId, Carbon::parse($from)->subDays($days)->toDateString(), Carbon::parse($from)->subDay()->toDateString(), $warehouses, $es);

        $kinds = [];
        foreach ($now['kinds'] as $key => $k) {
            $before = $prev['kinds'][$key]['amount'] ?? 0.0;
            $kinds[] = $k + ['key' => $key, 'previous' => round($before, 2),
                'change' => $before > 0 ? (int) round(($k['amount'] - $before) / $before * 100) : null,
                'share' => $now['total'] > 0 ? round($k['amount'] / $now['total'] * 100, 1) : 0];
        }
        usort($kinds, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'enabled' => true, 'from' => $from, 'to' => $to,
            'total' => round($now['total'], 2), 'previous' => round($prev['total'], 2),
            'income' => round($now['income'], 2), 'of_income' => $now['income'] > 0 ? (int) round($now['total'] / $now['income'] * 100) : null,
            'trips' => $now['trips'], 'per_trip' => $now['trips'] ? round($now['trips_cost'] / $now['trips'], 2) : null,
            'kinds' => $kinds, 'trend' => $now['trend'], 'unit' => $now['unit'],
        ];
    }

    private static function period(int $cid, string $from, string $to, ?array $warehouses, bool $es): array
    {
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $unit = $days <= 31 ? 'day' : ($days <= 185 ? 'week' : 'month');
        $bucket = function (string $date) use ($unit) {
            $d = Carbon::parse($date);

            return match ($unit) {
                'day' => $d->toDateString(),
                'week' => $d->copy()->startOfWeek()->toDateString(),
                default => $d->format('Y-m').'-01',
            };
        };

        $kinds = [];
        $trend = [];
        $add = function (string $key, string $name, string $group, float $amount, string $date) use (&$kinds, &$trend, $bucket) {
            if ($amount == 0.0) {
                return;
            }
            $kinds[$key] ??= ['name' => $name, 'group' => $group, 'amount' => 0.0];
            $kinds[$key]['amount'] += $amount;
            $b = $bucket($date);
            $trend[$b][$key] = ($trend[$b][$key] ?? 0) + $amount;
        };

        // The trips run: their costs by kind.
        $run = collect(TransportProfit::compute($cid, $from, $to, $warehouses)['routes'] ?? [])->flatMap(fn ($r) => $r['trips'])->where('result', '!=', 'pending');
        $income = 0.0;
        $tripsCost = 0.0;
        foreach ($run as $t) {
            $income += (float) $t['income']['total'];
            $tripsCost += (float) $t['costs']['total'];
            foreach (self::KINDS as $key => [$en, $sp]) {
                $add($key, $es ? $sp : $en, 'trips', (float) ($t['costs'][$key] ?? 0), substr((string) $t['departure_at'], 0, 10));
            }
        }

        // Services paid: Accounting -> Expenses of the agencies' locations, by category.
        $agencyWarehouses = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('warehouse_id')
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses ?: [0]))->pluck('warehouse_id')->unique()->all();
        $expenses = DB::table('expenses as e')->leftJoin('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->whereIn('e.warehouse_id', $agencyWarehouses ?: [0])->whereNull('e.deleted_at')->whereNull('e.payroll_id')
            ->whereBetween('e.date', [$from, $to])->get(['e.date', 'e.amount', 'c.name']);
        foreach ($expenses as $x) {
            $name = (string) ($x->name ?: ($es ? 'Otros gastos' : 'Other expenses'));
            $add('svc:'.$name, $es ? (self::CATEGORY_ES[$name] ?? $name) : $name, 'services', (float) $x->amount, (string) $x->date);
        }

        // Every bucket of the period, empty ones included, oldest first.
        $points = [];
        for ($d = Carbon::parse($bucket($from)); $d->lte(Carbon::parse($to)); $d = $unit === 'day' ? $d->addDay() : ($unit === 'week' ? $d->addWeek() : $d->addMonth())) {
            $k = $d->toDateString();
            $points[] = ['date' => $k, 'values' => array_map(fn ($v) => round($v, 2), $trend[$k] ?? [])];
        }

        return [
            'kinds' => array_map(fn ($k) => ['name' => $k['name'], 'group' => $k['group'], 'amount' => round($k['amount'], 2)], $kinds),
            'total' => array_sum(array_column($kinds, 'amount')), 'income' => $income, 'trips' => $run->count(), 'trips_cost' => $tripsCost,
            'trend' => $points, 'unit' => $unit,
        ];
    }
}
