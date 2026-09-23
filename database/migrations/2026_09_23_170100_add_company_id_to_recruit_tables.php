<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Recruit module (recruit_job_categories, recruit_jobs, recruit_candidates,
 * recruit_applications, recruit_interviews) had no company_id anywhere --
 * RecruitController's index/store/update/destroy methods for all five tables
 * were company-blind. Same pattern as 2026_09_21_130100_add_company_id_to_core_business_tables.php.
 */
return new class extends Migration
{
    private array $tables = [
        'recruit_job_categories',
        'recruit_jobs',
        'recruit_candidates',
        'recruit_applications',
        'recruit_interviews',
    ];

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
