<?php

namespace App\Http\Controllers\AccountingV2;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting dashboard -- GET accounting/v2/dashboard?from=&to=
 * (YYYY-MM-DD; none = last 30 days, from=all = since the first entry).
 * Multicompany: the acting company's entries and accounts (plus the shared
 * ones); a SuperAdmin working as AdminSystem (999) sees every company's.
 * The chart is daily up to ~3 months, monthly beyond that.
 */
class DashboardController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    public function summary(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'accounting_dashboard', Account::class);

        $result = [
            'kpi' => [
                'accounts' => 0,
                'journals_30d' => 0,
                'income_30d' => 0.0,
                'expense_30d' => 0.0,
            ],
            'chart' => [
                'labels' => [],
                'income' => [],
                'expense' => [],
            ],
            'range' => ['from' => null, 'to' => null, 'group' => 'day'],
        ];

        $requiredTables = [
            'acc_chart_of_accounts',
            'acc_journal_entries',
            'acc_journal_entry_lines',
        ];
        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                return response()->json($result);
            }
        }

        $all = optional($request->user('api'))->type === 'SuperAdmin' && $this->resolveActingCompanyId($request) === 999;
        $cid = $this->resolveActingCompanyId($request);
        $scoped = fn ($q, $col) => $all ? $q : $q->where($col, $cid);

        // Range: from/to as given; "all" = since the company's first entry.
        $today = Carbon::today();
        if ($request->get('from') === 'all') {
            $first = $scoped(DB::table('acc_journal_entries'), 'company_id')->min('date');
            $from = $first ? Carbon::parse($first)->startOfDay() : $today->copy();
            $to = $today->copy();
        } else {
            $from = $this->dateParam($request->get('from')) ?? $today->copy()->subDays(29);
            $to = $this->dateParam($request->get('to')) ?? $today->copy();
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $monthly = $from->diffInDays($to) > 92;
        $result['range'] = ['from' => $fromDate, 'to' => $toDate, 'group' => $monthly ? 'month' : 'day'];

        $result['kpi']['accounts'] = (int) DB::table('acc_chart_of_accounts')
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->where('company_id', $cid)->orWhereNull('company_id')))
            ->count();
        $result['kpi']['journals_30d'] = (int) $scoped(DB::table('acc_journal_entries'), 'company_id')
            ->whereBetween('date', [$fromDate, $toDate])
            ->count();

        $base = $scoped(DB::table('acc_journal_entry_lines as l')
            ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->leftJoin('acc_chart_of_accounts as c', 'c.id', '=', 'l.coa_id')
            ->where('j.status', 'posted')
            ->whereBetween('j.date', [$fromDate, $toDate]), 'j.company_id');

        $income = (clone $base)
            ->where('c.type', 'income')
            ->select(DB::raw('SUM(l.credit - l.debit) as amount'))
            ->value('amount');
        $expense = (clone $base)
            ->where('c.type', 'expense')
            ->select(DB::raw('SUM(l.debit - l.credit) as amount'))
            ->value('amount');

        $result['kpi']['income_30d'] = round((float) ($income ?? 0), 2);
        $result['kpi']['expense_30d'] = round((float) ($expense ?? 0), 2);

        $bucket = $monthly ? "DATE_FORMAT(j.date, '%Y-%m')" : 'DATE(j.date)';
        $rows = (clone $base)
            ->whereIn('c.type', ['income', 'expense'])
            ->select(
                DB::raw("{$bucket} as bucket"),
                DB::raw("SUM(CASE WHEN c.type = 'income' THEN (l.credit - l.debit) ELSE 0 END) as income"),
                DB::raw("SUM(CASE WHEN c.type = 'expense' THEN (l.debit - l.credit) ELSE 0 END) as expense")
            )
            ->groupBy(DB::raw($bucket))
            ->get()
            ->keyBy('bucket');

        $period = $monthly
            ? CarbonPeriod::create($from->copy()->startOfMonth(), '1 month', $to->copy()->startOfMonth())
            : CarbonPeriod::create($fromDate, $toDate);
        foreach ($period as $date) {
            $key = $monthly ? $date->format('Y-m') : $date->toDateString();
            $row = $rows->get($key);
            $result['chart']['labels'][] = $key;
            $result['chart']['income'][] = round((float) ($row->income ?? 0), 2);
            $result['chart']['expense'][] = round((float) ($row->expense ?? 0), 2);
        }

        return response()->json($result);
    }

    private function dateParam($value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
