<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Ports KT-Billing's multi-company boundary to Murano's core tables: users,
 * clients, sales, purchases, products, warehouses. Murano previously had
 * company_id only on the HR module (employees, departments, etc.) -- these
 * six had none, so every row implicitly belonged to "the whole install".
 *
 * Murano's live data is tiny (a handful of demo rows), so unlike Billing's
 * staged backfill (column without FK -> backfill -> add FK in a second
 * pass), it's safe here to default straight to company_id 1 (KcolTech, the
 * first row seeded by the previous migration) in one step -- there's no
 * batch of real rows that would sit on a wrong value in between.
 *
 * companies.id is a plain (signed) int(11) -- company_id below matches that
 * exactly, same gotcha as Billing (mismatched types fail the FK, errno 150).
 */
return new class extends Migration
{
    private array $tables = ['users', 'clients', 'sales', 'purchases', 'products', 'warehouses'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->integer('company_id')->default(1)->after('id');
            });
        }

        foreach ($this->tables as $table) {
            $fk = $table.'_company_id_foreign';
            $exists = DB::selectOne("
                SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
            ", [$table, $fk]);
            if ($exists->c > 0) {
                continue;
            }
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
