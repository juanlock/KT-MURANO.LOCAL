<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sales_agents had no company_id at all. Table is empty in every environment
 * checked (0 rows), so this is a straight schema add -- default(1) is just
 * the column default for any row inserted before the app sets it explicitly,
 * not a real backfill (nothing to backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_agents', 'company_id')) {
            Schema::table('sales_agents', function (Blueprint $table) {
                $table->integer('company_id')->default(1)->after('id');
            });
        }

        $fk = 'sales_agents_company_id_foreign';
        $exists = DB::selectOne("
            SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_agents' AND CONSTRAINT_NAME = ?
        ", [$fk]);
        if ($exists->c == 0) {
            Schema::table('sales_agents', function (Blueprint $table) {
                $table->foreign('company_id')->references('id')->on('companies');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales_agents', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
