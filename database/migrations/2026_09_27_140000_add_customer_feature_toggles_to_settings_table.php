<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Defaults -> "Default — Customers": per-company switches
 * for the three optional customer features. When one is off, every customer
 * UI tied to it (form fields, list columns, stats, actions, POS/sale widgets)
 * is hidden. Default on, so existing companies keep today's behavior.
 */
return new class extends Migration
{
    private array $columns = [
        'customer_loyalty_enabled',
        'customer_credit_limit_enabled',
        'customer_opening_balance_enabled',
    ];

    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (! Schema::hasColumn('settings', $column)) {
                    $table->boolean($column)->default(true);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
