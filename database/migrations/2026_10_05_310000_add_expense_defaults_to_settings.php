<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defaults -> Expenses: the payment method and account a new expense starts
 * with (per company, like Defaults -> Sales has for sales). Empty = none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'expense_default_payment_method_id')) {
                $table->unsignedInteger('expense_default_payment_method_id')->nullable();
            }
            if (! Schema::hasColumn('settings', 'expense_default_account_id')) {
                $table->unsignedInteger('expense_default_account_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['expense_default_payment_method_id', 'expense_default_account_id'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
