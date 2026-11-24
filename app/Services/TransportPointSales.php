<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * "Ventas por punto de venta" (Turismo, user's yes 2026-11-24): AuraSoft's "Liquidación General" as a board -- by branch,
 * point of sale and cashier: tickets, parcels, excess, collected at destination, income, cards, web / other channels, cash
 * expenses and the net each cashier hands in (TOTAL (ING - EGR)); the share sold online (Web, RedBus, Kupos) vs the
 * agencies. Reads the CURRENT rows (the latest file's figures). By SALE date.
 */
class TransportPointSales
{
    /** AuraSoft's online sellers appear as their own "branch". */
    public const ONLINE = ['WEB' => 'Web', 'REDBUS' => 'RedBus', 'KUPOS PERU' => 'Kupos'];

    /** The line's order (Lima, Trujillo, Chepén, Chiclayo), then the rest, then the online sellers. */
    private const ORDER = ['LIMA' => 1, 'TRUJILLO' => 2, 'CHEPEN' => 3, 'CHEPÉN' => 3, 'CHICLAYO' => 4];

    private const SUMS = ['tickets', 'parcels', 'excess', 'collected', 'income', 'card', 'web', 'channel', 'delivery', 'cash_out', 'expenses', 'net'];

    public static function compute(int $cid, string $from, string $to): array
    {
        $rows = DB::table('transport_aurasoft_liquidations')->where('company_id', $cid)->where('is_current', 1)
            ->whereBetween('sale_date', [$from, $to])->get(array_merge(['sale_date', 'branch', 'point', 'cashier'], self::SUMS));
        $range = DB::table('transport_aurasoft_liquidations')->where('company_id', $cid)->where('is_current', 1)
            ->selectRaw('MIN(sale_date) d1, MAX(sale_date) d2')->first();
        $sum = function ($g) {
            $o = [];
            foreach (self::SUMS as $k) {
                $o[$k] = round($g->sum($k), 2);
            }

            return $o;
        };
        $isOnline = fn ($branch) => isset(self::ONLINE[mb_strtoupper(trim((string) $branch))]);
        $rank = fn ($branch) => $isOnline($branch) ? 9 : (self::ORDER[mb_strtoupper(trim((string) $branch))] ?? 5);

        $branches = $rows->groupBy('branch')->map(function ($g, $branch) use ($sum, $isOnline) {
            $points = $g->groupBy('point')->map(function ($pg, $point) use ($sum) {
                $cashiers = $pg->groupBy('cashier')->map(fn ($cg, $cashier) => ['name' => $cashier ?: '—', 'days' => $cg->pluck('sale_date')->unique()->count()] + $sum($cg))
                    ->sortByDesc('income')->values()->all();

                return ['name' => $point ?: '—', 'cashiers' => $cashiers] + $sum($pg);
            })->sortByDesc('income')->values()->all();

            return ['name' => $branch ?: '—', 'online' => $isOnline($branch), 'points' => $points] + $sum($g);
        })->sortBy(fn ($b) => [$rank($b['name']), -$b['income']])->values()->all();

        $totals = $sum($rows);
        $online = [];
        foreach ($branches as $b) {
            if ($b['online']) {
                $online[self::ONLINE[mb_strtoupper(trim($b['name']))]] = $b['tickets'];
            }
        }
        $onlineSum = array_sum($online);
        $share = ['agencies' => round($totals['tickets'] - $onlineSum, 2)] + $online;

        return [
            'enabled' => true, 'from' => $from, 'to' => $to, 'available' => ['from' => $range->d1, 'to' => $range->d2],
            'totals' => $totals, 'share' => $share,
            'online_pct' => $totals['tickets'] > 0 ? round($onlineSum / $totals['tickets'] * 100, 1) : null,
            'branches' => $branches,
            'by_day' => $rows->groupBy('sale_date')->map(fn ($g, $d) => ['d' => $d, 'tickets' => round($g->sum('tickets'), 2), 'income' => round($g->sum('income'), 2),
                'net' => round($g->sum('net'), 2)])->sortKeys()->values()->all(),
        ];
    }
}
