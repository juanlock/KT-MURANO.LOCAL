<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Brings `size_guides` into the per-company model. Same rationale as
 * store_banners (2026_09_23_160200) -- storefront content that belongs to a
 * company the same way store_settings does, plain company_id column + FK.
 *
 * companies.id is a plain (signed) int(11) -- company_id below matches that
 * exactly, same gotcha as every other company_id migration this session
 * (mismatched types fail the FK, errno 150).
 */
return new class extends Migration
{
    private string $table = 'size_guides';

    public function up(): void
    {
        if (! Schema::hasColumn($this->table, 'company_id')) {
            Schema::table($this->table, function (Blueprint $blueprint) {
                $blueprint->integer('company_id')->default(1)->after('id');
            });
        }

        $fk = $this->table.'_company_id_foreign';
        $exists = DB::selectOne("
            SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ", [$this->table, $fk]);
        if ($exists->c == 0) {
            Schema::table($this->table, function (Blueprint $blueprint) {
                $blueprint->foreign('company_id')->references('id')->on('companies');
            });
        }
    }

    public function down(): void
    {
        Schema::table($this->table, function (Blueprint $blueprint) {
            $blueprint->dropForeign($this->table.'_company_id_foreign');
            $blueprint->dropColumn('company_id');
        });
    }
};
