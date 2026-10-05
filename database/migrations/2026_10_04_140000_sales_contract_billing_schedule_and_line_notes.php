<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Contracts -> recurring invoices:
 *  - billing_count: how many invoices in total (e.g. 5 = Jan..May); null =
 *    until the end date / open-ended.
 *  - billing_day_mode + billing_day: which day each invoice falls on --
 *    'same' (same day as the first invoice), monthly-type 'first' / 'last'
 *    day of the month or 'day' = billing_day (1-31, capped to the month's
 *    length); weekly 'day' = billing_day weekday (1 = Monday ... 7 = Sunday).
 *  - sales_contract_items.note: the line's note on each invoice, a template
 *    ({{month}}, {{number}}, {{week}}...) filled per invoice by
 *    App\Services\SalesContractBilling::renderNote().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_contracts', 'billing_count')) {
                $table->unsignedSmallInteger('billing_count')->nullable()->after('billing_interval');
            }
            if (! Schema::hasColumn('sales_contracts', 'billing_day_mode')) {
                $table->string('billing_day_mode', 10)->default('same')->after('billing_count');
            }
            if (! Schema::hasColumn('sales_contracts', 'billing_day')) {
                $table->unsignedTinyInteger('billing_day')->nullable()->after('billing_day_mode');
            }
        });
        Schema::table('sales_contract_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_contract_items', 'note')) {
                $table->string('note', 500)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_contracts', function (Blueprint $table) {
            foreach (['billing_count', 'billing_day_mode', 'billing_day'] as $col) {
                if (Schema::hasColumn('sales_contracts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('sales_contract_items', function (Blueprint $table) {
            if (Schema::hasColumn('sales_contract_items', 'note')) {
                $table->dropColumn('note');
            }
        });
    }
};
