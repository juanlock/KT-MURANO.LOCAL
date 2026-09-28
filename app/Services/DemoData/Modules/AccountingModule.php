<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;

/**
 * Accounting (V2): a few balanced manual journal entries, a budget and a VAT
 * report. The acc_* tables are not company-scoped in this install.
 *
 * Detection uses the tables people fill by hand (budgets, tax reports), not
 * acc_journal_entries: sales/payments auto-post journal entries, so that table
 * has rows as soon as anything is sold and would always skip the module.
 *
 * System account codes that AccountingHelper::getOrCreateCoa() looks up
 * (1000-CASH, 1100-AR, 4000-REV, ...) are reused when they exist but are never
 * created here -- a demo row under those codes would get picked up by real
 * postings and then vanish on reset. Missing ones get a "-DEMO" code instead.
 */
class AccountingModule extends DemoModule
{
    public function key(): string
    {
        return 'accounting';
    }

    public function label(): string
    {
        return 'Accounting';
    }

    public function dataTables(): array
    {
        return ['acc_budgets', 'acc_tax_reports'];
    }

    public function requiredTables(): array
    {
        return ['acc_chart_of_accounts', 'acc_journal_entries', 'acc_journal_entry_lines', 'acc_budgets', 'acc_budget_lines'];
    }

    public function generate(DemoContext $ctx): array
    {
        $createdAccounts = 0;
        // The chart is shared by every company: never lean on another
        // company's demo accounts (their reset would orphan our lines).
        $foreignDemo = DB::table('demo_records')
            ->where('record_type', 'acc_chart_of_accounts')
            ->where('company_id', '!=', $ctx->companyId)
            ->pluck('record_id')->all();
        $coa = function (string $systemCode, string $demoCode, string $name, string $type) use ($ctx, $foreignDemo, &$createdAccounts): int {
            foreach ([$systemCode, $demoCode] as $code) {
                $id = DB::table('acc_chart_of_accounts')->where('code', $code)
                    ->when($foreignDemo, fn ($q) => $q->whereNotIn('id', $foreignDemo))
                    ->orderBy('id')->value('id');
                if ($id) {
                    return (int) $id;
                }
            }
            $createdAccounts++;

            return $ctx->insert('acc_chart_of_accounts', [
                'code' => $demoCode, 'name' => $name, 'type' => $type, 'level' => 0, 'is_active' => 1,
            ]);
        };

        $cash = $coa('1000-CASH', '1000-CASH-DEMO', 'Cash', 'asset');
        $ar = $coa('1100-AR', '1100-AR-DEMO', 'Accounts Receivable', 'asset');
        $rev = $coa('4000-REV', '4000-REV-DEMO', 'Sales Revenue', 'income');
        // Non-system accounts: realistic codes no posting routine uses.
        $capital = $coa('3000-CAP', '3000-CAP', "Owner's Capital", 'equity');
        $accrued = $coa('2200-ACCR', '2200-ACCR', 'Accrued Salaries', 'liability');
        $rent = $coa('6100-RENT', '6100-RENT', 'Rent Expense', 'expense');
        $util = $coa('6200-UTIL', '6200-UTIL', 'Utilities Expense', 'expense');
        $salaries = $coa('6300-SAL', '6300-SAL', 'Salaries Expense', 'expense');
        $supplies = $coa('6400-SUP', '6400-SUP', 'Office Supplies Expense', 'expense');

        $entries = [
            ['Owner capital contribution', 'posted', 85, [[$cash, 15000, 0], [$capital, 0, 15000]]],
            ['Monthly office rent', 'posted', 60, [[$rent, 1800, 0], [$cash, 0, 1800]]],
            ['Electricity and internet bill', 'posted', 45, [[$util, 342.75, 0], [$cash, 0, 342.75]]],
            ['Invoice to corporate customer (on credit)', 'posted', 30, [[$ar, 4200, 0], [$rev, 0, 4200]]],
            ['Partial collection from corporate customer', 'posted', 15, [[$cash, 2500, 0], [$ar, 0, 2500]]],
            ['Printer paper and toner', 'posted', 10, [[$supplies, 186.4, 0], [$cash, 0, 186.4]]],
            ['Month-end salary accrual (to review)', 'draft', 2, [[$salaries, 6400, 0], [$accrued, 0, 6400]]],
        ];

        $entryCount = 0;
        $lineCount = 0;
        foreach ($entries as [$description, $status, $daysAgo, $lines]) {
            $date = now()->subDays($daysAgo)->toDateString();
            $entryId = $ctx->insert('acc_journal_entries', [
                'date' => $date,
                'reference_type' => 'manual',
                'reference_id' => null,
                'status' => $status,
                'posted_at' => $status === 'posted' ? now()->subDays($daysAgo) : null,
                'description' => $description,
                'created_by' => $ctx->userId,
            ]);
            $entryCount++;
            foreach ($lines as [$coaId, $debit, $credit]) {
                $ctx->insert('acc_journal_entry_lines', [
                    'journal_entry_id' => $entryId,
                    'coa_id' => $coaId,
                    'account_id' => null,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => $description,
                ]);
                $lineCount++;
            }
        }

        $budgetId = $ctx->insert('acc_budgets', [
            'name' => 'Operating Budget '.now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'currency' => 'USD',
            'notes' => 'Monthly spending targets for core operating expenses.',
            'is_active' => 1,
        ]);
        $budgetLines = 0;
        foreach ([[$rent, 1800], [$util, 400], [$salaries, 6500], [$supplies, 250]] as [$coaId, $amount]) {
            $ctx->insert('acc_budget_lines', [
                'budget_id' => $budgetId, 'coa_id' => $coaId, 'amount' => $amount, 'period' => 'monthly',
            ]);
            $budgetLines++;
        }

        $taxReports = 0;
        if ($ctx->columns('acc_tax_reports')) {
            $start = now()->subQuarter()->firstOfQuarter();
            $sales = 18450.00;
            $purchases = 9320.00;
            $ctx->insert('acc_tax_reports', [
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->lastOfQuarter()->toDateString(),
                'type' => 'vat',
                'taxable_sales' => $sales,
                'output_tax' => round($sales * 0.2, 2),
                'taxable_purchases' => $purchases,
                'input_tax' => round($purchases * 0.2, 2),
                'net_tax' => round(($sales - $purchases) * 0.2, 2),
                'source' => 'manual',
                'generated_at' => now(),
            ]);
            $taxReports++;
        }

        return array_filter([
            'Chart of accounts' => $createdAccounts,
            'Journal entries' => $entryCount,
            'Journal lines' => $lineCount,
            'Budgets' => 1,
            'Budget lines' => $budgetLines,
            'Tax reports' => $taxReports,
        ]);
    }
}
