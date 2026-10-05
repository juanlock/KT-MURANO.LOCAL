<?php

namespace App\Services\DemoData\Modules;

use App\Services\CompanyAccountingSeeder;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Regular expenses: the monthly bills a business pays (rent, utilities,
 * internet, software, insurance, accounting, bank / card fees, advertising,
 * office supplies) for the last 12 months, each on its usual day of the month,
 * in the company's own expense categories (the default set from
 * App\Support\DefaultAccounting -- seeded first if the company has none).
 * Fixed bills repeat the same amount; variable ones move around a base.
 * Tagged like every demo row, so Demo Data -> reset removes them.
 *
 * Not blocked by real expenses (dataTables() is empty): regular bills are
 * examples added next to whatever the company has; "already generated" still
 * stops a second run.
 */
class RegularExpensesModule extends DemoModule
{
    /** [category (default set name), base amount, variation (0-1), day of the month]. */
    private const BILLS = [
        ['Office rent', 1800, 0, 1],
        ['Software subscriptions', 180, 0.2, 3],
        ['Internet and telecommunications', 95, 0, 5],
        ['Utilities', 260, 0.35, 12],
        ['Insurance', 240, 0, 15],
        ['Office supplies', 110, 0.6, 18],
        ['Advertising', 450, 0.5, 20],
        ['Accounting services', 350, 0, 25],
        ['Credit card processing fees', 140, 0.4, 28],
        ['Bank fees', 28, 0.4, 28],
    ];

    private const MONTHS = 12;

    public function key(): string
    {
        return 'regular_expenses';
    }

    public function label(): string
    {
        return 'Regular expenses (monthly bills, last 12 months)';
    }

    public function dataTables(): array
    {
        return [];
    }

    public function requiredTables(): array
    {
        return ['expenses', 'expense_categories'];
    }

    public function generate(DemoContext $ctx): array
    {
        // The company's categories (default set); seed it if missing.
        $categories = fn () => DB::table('expense_categories')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->pluck('id', 'name');
        $byName = $categories();
        if ($byName->isEmpty()) {
            CompanyAccountingSeeder::seed($ctx->companyId);
            $byName = $categories();
        }
        $paymentMethodId = DB::table('payment_methods')->whereNull('deleted_at')
            ->when($ctx->hasColumn('payment_methods', 'is_active'), fn ($q) => $q->where('is_active', 1))
            ->orderByRaw("LOWER(name) LIKE '%bank%' desc")->orderBy('id')->value('id');

        $today = Carbon::today();
        $count = 0;
        $total = 0.0;
        $ids = [];
        for ($m = self::MONTHS - 1; $m >= 0; $m--) {
            $month = $today->copy()->startOfMonth()->subMonthsNoOverflow($m);
            foreach (self::BILLS as $i => [$name, $base, $vary, $day]) {
                $categoryId = $byName[$name] ?? null;
                $date = $month->copy()->day(min($day, $month->daysInMonth));
                if (! $categoryId || $date->gt($today)) {
                    continue;
                }
                $amount = $vary > 0 ? round($base * (1 + (random_int(-100, 100) / 100) * $vary), 2) : (float) $base;
                $ids[] = $ctx->insert('expenses', [
                    'date' => $date->toDateString(),
                    'Ref' => 'DEMO-REG-'.$date->format('Ym').'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'user_id' => $ctx->userId,
                    'expense_category_id' => $categoryId,
                    'warehouse_id' => $ctx->warehouse(),
                    'payment_method_id' => $paymentMethodId,
                    'account_id' => null,
                    'details' => $name.' — '.$date->format('F Y'),
                    'amount' => $amount,
                    'created_at' => $date->copy()->setTime(9, 0),
                    'updated_at' => $date->copy()->setTime(9, 0),
                ]);
                $count++;
                $total += $amount;
            }
        }

        // Posted to the journal like a form-made expense (Accounting shows them).
        $journal = $ctx->postJournal('expenses', 'fromExpense', $ids);

        return ['Regular expenses' => $count, 'Months' => self::MONTHS, 'Total amount' => (int) round($total), 'Journal entries' => $journal];
    }
}
