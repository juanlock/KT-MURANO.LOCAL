<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds company_id to the product-taxonomy/supplier tables that had none --
 * categories, subcategories, brands, units, providers -- ahead of syncing
 * Billing's actual product/sales/purchase data into Murano (products,
 * sales, purchases already had company_id from the earlier core-tables
 * migration; these five were missed because Murano currently has zero rows
 * in any of them, so there's no backfill step needed here, just add + FK in
 * one pass.
 */
return new class extends Migration
{
    private array $tables = ['categories', 'subcategories', 'brands', 'units', 'providers'];

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
