<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring expenses: a bill with the same amount every period (office rent,
 * internet, software, insurance...). Saved once from Create Expense ("How
 * often?"); App\Services\RecurringExpenses creates each next expense on its
 * date (daily command expenses:recurring + a catch-up when the Expenses list
 * opens). expenses.recurring_expense_id links each generated expense back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recurring_expenses')) {
            Schema::create('recurring_expenses', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('warehouse_id');
                $table->unsignedInteger('expense_category_id');
                $table->unsignedInteger('payment_method_id')->nullable();
                $table->unsignedInteger('account_id')->nullable();
                $table->decimal('amount', 15, 2);
                $table->text('details')->nullable();
                $table->string('frequency', 16); // weekly | monthly | quarterly | yearly
                $table->date('start_date');
                $table->date('next_date')->index();
                $table->date('end_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->date('last_generated_on')->nullable();
                $table->unsignedInteger('generated_count')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasColumn('expenses', 'recurring_expense_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('recurring_expense_id')->nullable()->index()->after('payroll_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expenses', 'recurring_expense_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn('recurring_expense_id');
            });
        }
        Schema::dropIfExists('recurring_expenses');
    }
};
