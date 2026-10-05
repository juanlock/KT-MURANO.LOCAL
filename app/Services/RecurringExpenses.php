<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Expense;
use App\Models\RecurringExpense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recurring expenses: creates the expenses whose date has come, exactly like
 * Create Expense does (Eloquent create -> the AccountingV2 listener posts the
 * journal; the paying account's balance goes down), then moves next_date one
 * period on. A schedule that was missed for a while catches up (one expense
 * per missed period, each on its own date). Run by expenses:recurring (daily)
 * and when the Expenses list opens, so nothing depends on the scheduler.
 */
class RecurringExpenses
{
    /** Next date after $date for a frequency (month ends kept: Jan 31 -> Feb 28). */
    public static function advance(string $date, string $frequency): string
    {
        $d = Carbon::parse($date);

        $next = match ($frequency) {
            'weekly' => $d->addWeek(),
            'quarterly' => $d->addMonthsNoOverflow(3),
            'yearly' => $d->addYearNoOverflow(),
            default => $d->addMonthNoOverflow(),
        };

        return $next->toDateString();
    }

    /** Create every due expense (one company or all). Returns expenses created. */
    public static function generateDue(?int $companyId = null): int
    {
        $today = Carbon::today()->toDateString();
        $created = 0;
        $due = RecurringExpense::where('is_active', true)->where('next_date', '<=', $today)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('id')->get();

        foreach ($due as $plan) {
            // Locked per schedule so two runs at once can't create the same expense twice.
            $created += DB::transaction(function () use ($plan, $today) {
                $plan = RecurringExpense::whereKey($plan->id)->lockForUpdate()->first();
                $n = 0;
                while ($plan && $plan->is_active && $plan->next_date <= $today
                    && (! $plan->end_date || $plan->next_date <= $plan->end_date) && $n < 120) {
                    self::createExpense($plan, $plan->next_date);
                    $plan->last_generated_on = $plan->next_date;
                    $plan->generated_count++;
                    $plan->next_date = self::advance($plan->next_date, $plan->frequency);
                    $n++;
                }
                if ($plan && $plan->end_date && $plan->next_date > $plan->end_date) {
                    $plan->is_active = false; // finished
                }
                $plan?->save();

                return $n;
            });
        }

        return $created;
    }

    public static function createExpense(RecurringExpense $plan, string $date): Expense
    {
        $expense = Expense::create([
            'user_id' => $plan->user_id,
            'date' => $date,
            'Ref' => app(\App\Http\Controllers\ExpensesController::class)->getNumberOrder(),
            'payment_method_id' => $plan->payment_method_id,
            'warehouse_id' => $plan->warehouse_id,
            'expense_category_id' => $plan->expense_category_id,
            'account_id' => $plan->account_id,
            'details' => $plan->details,
            'amount' => $plan->amount,
            'recurring_expense_id' => $plan->id,
        ]);
        if ($plan->account_id && ($account = Account::find($plan->account_id))) {
            $account->update(['balance' => $account->balance - $plan->amount]);
        }

        return $expense;
    }
}
