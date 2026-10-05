<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW FEATURE - SAFE ADDITION
 *
 * Year-end close, per company (multicompany): each company's income and
 * expense accounts for the year are closed into ITS retained earnings
 * (company chart 3020, else the shared 3100), in a closing entry of that
 * company. Entries without a company (old shared postings) close together.
 * --company=ID closes one company only.
 */
class AccountingCloseYear extends Command
{
    protected $signature = 'accounting:close-year {--year=} {--company=}';

    protected $description = 'Create year-end closing entries (per company) transferring P&L to retained earnings';

    public function handle(): int
    {
        if (! config('accounting_v2.enabled', true)) {
            $this->info('Accounting V2 is disabled. Nothing to do.');

            return 0;
        }
        if (! Schema::hasTable('acc_journal_entry_lines')) {
            $this->warn('Accounting tables not installed. Skipping.');

            return 0;
        }

        $year = (int) ($this->option('year') ?: (int) date('Y') - 1);
        $periodEnd = Carbon::create($year, 12, 31)->toDateString();
        $periodStart = Carbon::create($year, 1, 1)->toDateString();

        $companies = DB::table('acc_journal_entries')->where('status', 'posted')
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->where('reference_type', '!=', 'year_close')
            ->when($this->option('company'), fn ($q) => $q->where('company_id', (int) $this->option('company')))
            ->distinct()->pluck('company_id');

        if ($companies->isEmpty()) {
            $this->info("No P&L activity for {$year}. Nothing to close.");

            return 0;
        }
        foreach ($companies as $companyId) {
            $this->closeCompany($year, $periodStart, $periodEnd, $companyId ? (int) $companyId : null);
        }

        return 0;
    }

    private function closeCompany(int $year, string $periodStart, string $periodEnd, ?int $companyId): void
    {
        $who = $companyId ? "company {$companyId}" : 'shared (no company)';
        $already = DB::table('acc_journal_entries')->where('reference_type', 'year_close')->where('reference_id', $year)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))->exists();
        if ($already) {
            $this->info("{$year} already closed for {$who}.");

            return;
        }

        $base = DB::table('acc_journal_entry_lines as l')
            ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->leftJoin('acc_chart_of_accounts as c', 'c.id', '=', 'l.coa_id')
            ->where('j.status', 'posted')
            ->where('j.reference_type', '!=', 'year_close')
            ->whereBetween('j.date', [$periodStart, $periodEnd])
            ->when($companyId, fn ($q) => $q->where('j.company_id', $companyId), fn ($q) => $q->whereNull('j.company_id'));

        $incomeRows = (clone $base)
            ->where('c.type', 'income')
            ->select('c.id as coa_id', DB::raw('SUM(l.credit - l.debit) as amount'))
            ->groupBy('c.id')->get();
        $expenseRows = (clone $base)
            ->where('c.type', 'expense')
            ->select('c.id as coa_id', DB::raw('SUM(l.debit - l.credit) as amount'))
            ->groupBy('c.id')->get();

        $totalIncome = (float) $incomeRows->sum('amount');
        $totalExpense = (float) $expenseRows->sum('amount');
        $net = $totalIncome - $totalExpense; // profit positive

        if (abs($totalIncome) < 0.0001 && abs($totalExpense) < 0.0001) {
            $this->info("No P&L activity for {$year} ({$who}). Nothing to close.");

            return;
        }

        $retained = $this->retainedEarnings($companyId);

        DB::transaction(function () use ($year, $periodEnd, $companyId, $incomeRows, $expenseRows, $net, $retained) {
            $journalId = DB::table('acc_journal_entries')->insertGetId([
                'company_id' => $companyId,
                'date' => $periodEnd,
                'description' => "Year-end closing {$year}",
                'reference_type' => 'year_close',
                'reference_id' => $year,
                'status' => 'posted',
                'posted_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $line = fn ($coa, $debit, $credit, $memo) => DB::table('acc_journal_entry_lines')->insert([
                'journal_entry_id' => $journalId, 'coa_id' => $coa, 'debit' => $debit, 'credit' => $credit, 'memo' => $memo,
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);

            // Reverse out income (debit) and expenses (credit)
            foreach ($incomeRows as $r) {
                if ((float) $r->amount > 0) {
                    $line($r->coa_id, (float) $r->amount, 0, 'Close income');
                }
            }
            foreach ($expenseRows as $r) {
                if ((float) $r->amount > 0) {
                    $line($r->coa_id, 0, (float) $r->amount, 'Close expense');
                }
            }
            // Offset to retained earnings: profit credits it, loss debits it.
            $net >= 0
                ? $line($retained, 0, (float) $net, 'Transfer net profit')
                : $line($retained, (float) abs($net), 0, 'Transfer net loss');
        });

        $this->info("Year {$year} closed for {$who} with net ".number_format($net, 2));
    }

    /** The company's Retained Earnings (its chart's 3020), else the shared one (3100, created if missing). */
    private function retainedEarnings(?int $companyId): int
    {
        if ($companyId && Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
            $code = \App\Support\DefaultAccounting::POSTING_CODES['3100'] ?? '3020';
            $id = DB::table('acc_chart_of_accounts')->where('company_id', $companyId)->where('code', $code)->value('id');
            if ($id) {
                return (int) $id;
            }
        }
        $retainedCode = Config::get('accounting_v2.codes.retained_earnings', '3100');
        $id = DB::table('acc_chart_of_accounts')->where('code', $retainedCode)->whereNull('company_id')->value('id')
            ?? DB::table('acc_chart_of_accounts')->where('code', $retainedCode)->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('acc_chart_of_accounts')->insertGetId([
            'account_id' => null, 'code' => $retainedCode, 'name' => 'Retained Earnings', 'type' => 'equity',
            'parent_id' => null, 'level' => 0, 'is_active' => true, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }
}
