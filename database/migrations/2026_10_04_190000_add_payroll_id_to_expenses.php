<?php

use App\Models\Payroll;
use App\Services\PayrollExpenseSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM payroll -> accounting: each payroll gets a linked "Salaries and wages"
 * expense (expenses.payroll_id). Existing paid payrolls are linked now
 * (expense + journal entry, see App\Services\PayrollExpenseSync).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'payroll_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedInteger('payroll_id')->nullable()->index()->after('account_id');
            });
        }

        Payroll::whereNull('deleted_at')->orderBy('id')->get()
            ->each(fn ($payroll) => PayrollExpenseSync::sync($payroll));
    }

    public function down(): void
    {
        if (Schema::hasColumn('expenses', 'payroll_id')) {
            // Linked expenses only exist because of the payroll: drop them with the link.
            $ids = DB::table('expenses')->whereNotNull('payroll_id')->pluck('id');
            DB::table('acc_journal_entries')->where('reference_type', 'expense')->whereIn('reference_id', $ids)->get(['id'])
                ->each(function ($j) {
                    DB::table('acc_journal_entry_lines')->where('journal_entry_id', $j->id)->delete();
                    DB::table('acc_journal_entries')->where('id', $j->id)->delete();
                });
            DB::table('expenses')->whereIn('id', $ids)->delete();
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropIndex(['payroll_id']);
                $table->dropColumn('payroll_id');
            });
        }
    }
};
