<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds company_id to the rest of the business tables that had none:
 * quotations, adjustments, transfers, damages (Billing has company_id on
 * all four; Murano didn't), plus sale_returns and shipments (Billing itself
 * still lacks company_id on these two -- same class of gap already found
 * and fixed directly on purchase_returns this session, added here too since
 * there's a real, direct fix available and no data to risk).
 *
 * All six tables are currently empty in Murano (verified before writing
 * this), so straight add+FK, no backfill needed.
 */
return new class extends Migration
{
    private array $tables = ['quotations', 'sale_returns', 'adjustments', 'transfers', 'damages', 'shipments'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->integer('company_id')->default(1)->after('id');
            });
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('company_id')->references('id')->on('companies');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign($table.'_company_id_foreign');
                $blueprint->dropColumn('company_id');
            });
        }
    }
};
