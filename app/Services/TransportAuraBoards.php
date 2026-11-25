<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Two dashboard boards from AuraSoft's files (user's yes, 2026-11-24):
 *  - cashbook(): "Caja y clientes", from the Liquidación Detallada (transport_aurasoft_movements, current rows, by sale
 *    date): cancelled documents by point / cashier, cash expenses by point, how the customers paid (cards, Yape), the
 *    customers with DNI / RUC who buy most.
 *  - parcels(): "Encomiendas AuraSoft": the parcels still to be delivered (the latest kardex of each branch,
 *    transport_aurasoft_parcels) by age and status, and what is left to collect at destination: parcels issued "pago
 *    destino" (ENC_PAG) with no "cobro destino" (COB_DES) for the same series-number in the Liquidación Detallada.
 * "Late" = more than LATE_DAYS since it was issued (ours: the customer is told 1 to 3 days).
 */
class TransportAuraBoards
{
    public const LATE_DAYS = 3;

    private const CARD_MODULES = ['VEN_TAR', 'ENC_TAR', 'EXC_TAR'];
    private const SALE_MODULES = ['PAS_ING', 'VEN_ENC', 'VEN_EXE', 'COB_DES'];
    private const EXPENSE_MODULES = ['EGR_CPA', 'EGR_CEN'];

    public static function cashbook(int $cid, string $from, string $to): array
    {
        $base = fn () => DB::table('transport_aurasoft_movements')->where('company_id', $cid)->where('is_current', 1)->whereBetween('sale_date', [$from, $to]);
        $active = fn () => $base()->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'ANULADO'));
        $available = DB::table('transport_aurasoft_movements')->where('company_id', $cid)->where('is_current', 1)->selectRaw('MIN(sale_date) d1, MAX(sale_date) d2')->first();

        $cancel = $base()->where('status', 'ANULADO');
        $cancelTotals = (clone $cancel)->selectRaw('COUNT(*) n, COALESCE(SUM(ABS(total)),0) amount')->first();
        $cancelBy = (clone $cancel)->groupBy('branch', 'point', 'cashier')
            ->selectRaw('branch, point, cashier, COUNT(*) n, SUM(ABS(total)) amount')->orderByDesc('n')->limit(12)->get();
        $docs = (int) $base()->count();

        $exp = $active()->whereIn('module', self::EXPENSE_MODULES);
        $expTotals = (clone $exp)->selectRaw('COUNT(*) n, COALESCE(SUM(ABS(total)),0) amount')->first();
        $expBy = (clone $exp)->groupBy('branch', 'point')->selectRaw('branch, point, COUNT(*) n, SUM(ABS(total)) amount, MAX(ABS(total)) biggest')
            ->orderByDesc('amount')->limit(12)->get();

        $sales = (float) $active()->whereIn('module', self::SALE_MODULES)->sum('income');
        // The card / Yape totals are the *_TAR rows; the card's kind is only on the ticket rows (PAS_ING), so parcels and
        // excess paid by card show as one line.
        $cards = (float) $active()->whereIn('module', self::CARD_MODULES)->selectRaw('COALESCE(SUM(ABS(total)),0) s')->value('s');
        $pay = $active()->where('module', 'PAS_ING')->whereNotNull('card_type')->where('card_type', '!=', '')->groupBy('card_type')
            ->selectRaw('card_type card, COUNT(*) n, SUM(income) amount')->orderByDesc('amount')->get();
        $otherCards = max(0.0, $cards - (float) $pay->sum('amount'));
        if ($otherCards > 0.005) {
            $pay->push((object) ['card' => 'CARD_PARCELS', 'n' => null, 'amount' => $otherCards]);
        }
        $online = $active()->whereIn('module', ['WEB_EMI', 'RED_BUS', 'DEL_EMI'])->groupBy('module')->selectRaw('module, SUM(ABS(total)) amount')->pluck('amount', 'module');
        $onlineSum = (float) $online->sum();
        $cash = max(0.0, $sales - $cards - $onlineSum);
        $methods = array_values(array_filter([
            ['key' => 'cash', 'amount' => round($cash, 2)],
            ...$pay->map(fn ($p) => ['key' => $p->card, 'amount' => round((float) $p->amount, 2), 'n' => (int) $p->n])->all(),
            ['key' => 'WEB', 'amount' => round((float) ($online['WEB_EMI'] ?? 0), 2)],
            ['key' => 'REDBUS', 'amount' => round((float) ($online['RED_BUS'] ?? 0), 2)],
            ['key' => 'DELIVERY', 'amount' => round((float) ($online['DEL_EMI'] ?? 0), 2)],
        ], fn ($m) => $m['amount'] > 0));

        $clients = $active()->whereIn('module', self::SALE_MODULES)->whereNotNull('client_doc')->where('client_doc', '!=', '')
            ->groupBy('client_doc')->selectRaw('client_doc, MAX(client) client, COUNT(*) n, SUM(income) amount, SUM(module = \'VEN_ENC\' OR module = \'COB_DES\') parcels')
            ->orderByDesc('amount')->limit(15)->get()
            ->map(fn ($c) => ['doc' => $c->client_doc, 'name' => trim(str_replace(',', ' ', (string) $c->client)), 'company' => strlen($c->client_doc) === 11,
                'n' => (int) $c->n, 'parcels' => (int) $c->parcels, 'amount' => round((float) $c->amount, 2)]);
        $kinds = $active()->whereIn('module', self::SALE_MODULES)->whereNotNull('client_doc')->where('client_doc', '!=', '')
            ->selectRaw('COUNT(DISTINCT CASE WHEN LENGTH(client_doc) = 11 THEN client_doc END) ruc, COUNT(DISTINCT CASE WHEN LENGTH(client_doc) = 8 THEN client_doc END) dni,
                SUM(CASE WHEN LENGTH(client_doc) = 11 THEN income ELSE 0 END) ruc_amount')->first();

        return [
            'enabled' => true, 'from' => $from, 'to' => $to, 'available' => ['from' => $available->d1, 'to' => $available->d2],
            'totals' => [
                'docs' => $docs, 'cancelled' => (int) $cancelTotals->n, 'cancelled_amount' => round((float) $cancelTotals->amount, 2),
                'cancelled_pct' => $docs ? round($cancelTotals->n * 100 / $docs, 1) : 0,
                'expenses' => round((float) $expTotals->amount, 2), 'expense_n' => (int) $expTotals->n,
                'sales' => round($sales, 2), 'cards' => round($cards, 2), 'cash' => round($cash, 2), 'online' => round($onlineSum, 2),
                'ruc' => (int) ($kinds->ruc ?? 0), 'dni' => (int) ($kinds->dni ?? 0), 'ruc_amount' => round((float) ($kinds->ruc_amount ?? 0), 2),
            ],
            'cancelled_by' => $cancelBy->map(fn ($r) => ['branch' => $r->branch, 'point' => $r->point, 'cashier' => $r->cashier, 'n' => (int) $r->n, 'amount' => round((float) $r->amount, 2)]),
            'expenses_by' => $expBy->map(fn ($r) => ['branch' => $r->branch, 'point' => $r->point, 'n' => (int) $r->n, 'amount' => round((float) $r->amount, 2), 'biggest' => round((float) $r->biggest, 2)]),
            'methods' => $methods,
            'clients' => $clients,
        ];
    }

    public static function parcels(int $cid): array
    {
        $today = now()->toDateString();
        $cur = fn () => DB::table('transport_aurasoft_parcels')->where('company_id', $cid)->where('is_current', 1);
        $rows = $cur()->get(['branch', 'origin', 'emitted_on', 'document', 'consignee', 'detail', 'amount', 'pay', 'sender', 'point', 'status']);
        $files = DB::table('transport_aurasoft_imports')->where('company_id', $cid)->where('kind', 'parcels')
            ->whereIn('id', $cur()->distinct()->pluck('import_id'))->get(['id', 'created_at', 'period_to']);
        $buckets = ['0-1' => 0, '2-3' => 0, '4-7' => 0, '8-15' => 0, '16+' => 0];
        $byBranch = []; $statuses = []; $late = []; $amount = 0.0; $toCollect = 0; $toCollectAmount = 0.0;
        foreach ($rows as $r) {
            $age = $r->emitted_on ? (int) floor((strtotime($today) - strtotime($r->emitted_on)) / 86400) : null;
            $b = $age === null ? '16+' : ($age <= 1 ? '0-1' : ($age <= 3 ? '2-3' : ($age <= 7 ? '4-7' : ($age <= 15 ? '8-15' : '16+'))));
            $buckets[$b]++;
            $key = $r->branch ?: '—';
            $byBranch[$key] ??= ['branch' => $key, 'n' => 0, 'late' => 0, 'amount' => 0.0, 'statuses' => []];
            $byBranch[$key]['n']++;
            $byBranch[$key]['amount'] += (float) $r->amount;
            $st = $r->status ?: '—';
            $byBranch[$key]['statuses'][$st] = ($byBranch[$key]['statuses'][$st] ?? 0) + 1;
            $statuses[$st] = ($statuses[$st] ?? 0) + 1;
            $amount += (float) $r->amount;
            if ($r->pay === 'PD') {
                $toCollect++;
                $toCollectAmount += (float) $r->amount;
            }
            if ($age !== null && $age > self::LATE_DAYS) {
                $byBranch[$key]['late']++;
                $late[] = ['branch' => $r->branch, 'emitted_on' => $r->emitted_on, 'days' => $age, 'document' => $r->document, 'consignee' => $r->consignee,
                    'detail' => $r->detail, 'amount' => (float) $r->amount, 'pay' => $r->pay, 'point' => $r->point, 'status' => $r->status];
            }
        }
        usort($late, fn ($a, $b) => $b['days'] <=> $a['days']);
        arsort($statuses);

        return [
            'enabled' => true, 'late_days' => self::LATE_DAYS,
            'files' => $files->map(fn ($f) => ['id' => $f->id, 'at' => (string) $f->created_at, 'to' => $f->period_to])->values(),
            'totals' => ['n' => $rows->count(), 'amount' => round($amount, 2), 'late' => count($late), 'to_collect' => $toCollect, 'to_collect_amount' => round($toCollectAmount, 2)],
            'ages' => $buckets, 'statuses' => $statuses,
            'branches' => array_values(array_map(fn ($b) => ['amount' => round($b['amount'], 2)] + $b, $byBranch)),
            'late' => array_slice($late, 0, 60),
            'receivable' => self::receivable($cid),
        ];
    }

    /** Parcels issued "pago destino" (ENC_PAG) without their "cobro destino" (COB_DES) in the Liquidación Detallada. */
    private static function receivable(int $cid): array
    {
        $base = fn () => DB::table('transport_aurasoft_movements')->where('company_id', $cid)->where('is_current', 1)
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'ANULADO'));
        $range = DB::table('transport_aurasoft_movements')->where('company_id', $cid)->where('is_current', 1)->selectRaw('MIN(sale_date) d1, MAX(sale_date) d2')->first();
        if (! $range || ! $range->d2) {
            return ['available' => false];
        }
        $paid = $base()->where('module', 'COB_DES')->selectRaw("CONCAT(series,'-',number) k")->pluck('k')->flip();
        $open = [];
        foreach ($base()->where('module', 'ENC_PAG')->orderBy('sale_date')->get(['sale_date', 'branch', 'point', 'series', 'number', 'client', 'client_doc', 'total']) as $r) {
            if (! isset($paid[$r->series.'-'.$r->number])) {
                $open[] = $r;
            }
        }
        $ref = $range->d2;
        $buckets = ['0-3' => [0, 0.0], '4-7' => [0, 0.0], '8-15' => [0, 0.0], '16-30' => [0, 0.0], '31+' => [0, 0.0]];
        $byPoint = []; $total = 0.0; $list = [];
        foreach ($open as $r) {
            $amt = abs((float) $r->total);
            $age = (int) floor((strtotime($ref) - strtotime($r->sale_date)) / 86400);
            $b = $age <= 3 ? '0-3' : ($age <= 7 ? '4-7' : ($age <= 15 ? '8-15' : ($age <= 30 ? '16-30' : '31+')));
            $buckets[$b][0]++;
            $buckets[$b][1] += $amt;
            $k = ($r->branch ?: '—').' · '.($r->point ?: '—');
            $byPoint[$k] ??= ['branch' => $r->branch, 'point' => $r->point, 'n' => 0, 'amount' => 0.0, 'oldest' => $r->sale_date];
            $byPoint[$k]['n']++;
            $byPoint[$k]['amount'] += $amt;
            $total += $amt;
            $list[] = ['date' => $r->sale_date, 'days' => $age, 'doc' => $r->series.'-'.$r->number, 'branch' => $r->branch, 'point' => $r->point,
                'client' => trim(str_replace(',', ' ', (string) $r->client)), 'client_doc' => $r->client_doc, 'amount' => round($amt, 2)];
        }
        usort($byPoint, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        usort($list, fn ($a, $b) => $b['days'] <=> $a['days']);

        return [
            'available' => true, 'from' => $range->d1, 'to' => $range->d2, 'n' => count($open), 'amount' => round($total, 2),
            'issued' => (int) $base()->where('module', 'ENC_PAG')->count(),
            'ages' => array_map(fn ($b) => ['n' => $b[0], 'amount' => round($b[1], 2)], $buckets),
            'by_point' => array_map(fn ($p) => ['amount' => round($p['amount'], 2)] + $p, array_slice($byPoint, 0, 12)),
            'oldest' => array_slice($list, 0, 40),
        ];
    }
}
