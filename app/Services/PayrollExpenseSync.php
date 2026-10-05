<?php

namespace App\Services;

use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Models\Expense;
use App\Models\Payroll;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\AccountingV2\AccountingHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HRM payroll -> accounting. Every paid payroll has one linked expense
 * (expenses.payroll_id) in the employee company's "Salaries and wages"
 * category, so it shows in Expenses / expense reports and posts its journal
 * entry (Dr Salaries and wages 7010 / Cr the payroll's bank account, or Cash).
 *
 * The bank account balance stays the payroll's job (PayrollController already
 * moves it), so the linked expense never touches Account::balance -- and the
 * Expenses screen refuses to edit/delete it (change the payroll instead).
 */
class PayrollExpenseSync
{
    use SkipsWhenManual;

    /** Create or refresh the payroll's expense (and re-post its journal when it changed). */
    public static function sync(Payroll $payroll): ?Expense
    {
        return (new self())->syncPayroll($payroll);
    }

    /** Payroll deleted: delete its expense and reverse the journal. */
    public static function remove(Payroll $payroll): void
    {
        $self = new self();
        $expense = Expense::where('payroll_id', $payroll->id)->whereNull('deleted_at')->first();
        if (! $expense) {
            return;
        }
        Expense::whereKey($expense->id)->update(['deleted_at' => Carbon::now()]);
        if (! $self->shouldSkipForModel($expense)) {
            AccountingHelper::reverseExpense($expense);
        }
    }

    private function syncPayroll(Payroll $payroll): ?Expense
    {
        $companyId = (int) (DB::table('employees')->where('id', $payroll->employee_id)->value('company_id') ?: 0);
        $categoryId = $companyId ? $this->salaryCategory($companyId) : null;
        $warehouseId = $companyId ? $this->warehouse($companyId) : null;
        if (! $categoryId || ! $warehouseId) {
            return null; // company without categories/locations: nothing to link
        }

        $employee = DB::table('employees')->where('id', $payroll->employee_id)->first(['firstname', 'lastname', 'username']);
        $name = trim(($employee->firstname ?? '').' '.($employee->lastname ?? '')) ?: ($employee->username ?? '');
        $values = [
            'date' => $payroll->date,
            'expense_category_id' => $categoryId,
            'warehouse_id' => $warehouseId,
            'account_id' => $payroll->account_id ?: null,
            'payment_method_id' => $payroll->payment_method_id,
            'details' => 'Payroll '.$payroll->Ref.' — '.$name,
            'amount' => $payroll->amount,
        ];

        $expense = Expense::where('payroll_id', $payroll->id)->whereNull('deleted_at')->first();
        if (! $expense) {
            // Eloquent create: the AccountingV2 observer posts the journal.
            return Expense::create($values + [
                'user_id' => $payroll->user_id,
                'Ref' => app(\App\Http\Controllers\ExpensesController::class)->getNumberOrder(),
                'payroll_id' => $payroll->id,
            ]);
        }

        $before = $expense->replicate();
        $before->id = $expense->id;
        // Query update (no observer): the journal is redone below as one
        // reverse + re-post, so a new date or bank account is reflected too.
        Expense::whereKey($expense->id)->update($values);
        $expense->refresh();

        $changed = (float) $before->amount !== (float) $expense->amount
            || (string) $before->date !== (string) $expense->date
            || (int) $before->account_id !== (int) $expense->account_id
            || (int) $before->expense_category_id !== (int) $expense->expense_category_id
            || (int) $before->warehouse_id !== (int) $expense->warehouse_id;
        if ($changed && ! $this->shouldSkipForModel($expense)) {
            AccountingHelper::reverseExpense($before);
            AccountingHelper::fromExpense($expense);
        }

        return $expense;
    }

    /** The company's "Salaries and wages" category (account 7010), seeding the default set if missing. */
    private function salaryCategory(int $companyId): ?int
    {
        $find = fn () => DB::table('expense_categories')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('coa_code', '7010')->orWhere('name', 'Salaries and wages'))
            // "Direct labor" shares 7010: the name decides first.
            ->orderByRaw("name = 'Salaries and wages' desc")->orderBy('id')->value('id');
        $id = $find();
        if (! $id) {
            CompanyAccountingSeeder::seed($companyId);
            $id = $find();
        }

        return $id ? (int) $id : null;
    }

    /** The company's default location (Settings), else its first one. */
    private function warehouse(int $companyId): ?int
    {
        $default = Setting::where('company_id', $companyId)->whereNull('deleted_at')->value('warehouse_id');
        if ($default && Warehouse::whereKey($default)->where('company_id', $companyId)->whereNull('deleted_at')->exists()) {
            return (int) $default;
        }
        $id = Warehouse::where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }
}
