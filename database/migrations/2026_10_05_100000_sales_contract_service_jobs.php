<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Contracts -> recurring Service Jobs (visits), e.g. weekly lawn
 * mowing billed monthly. A contract with jobs_enabled creates one service
 * job per visit on its own schedule (same rules as the invoice schedule:
 * frequency x interval, day rule, count, first date), jobs_lead_days before
 * the visit, at jobs_time. The visit's title can use the invoice-note
 * placeholders ({{weekday}}, {{date}}, {{number}}...).
 * service_jobs.sales_contract_id + contract_occurrence link each job back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_contracts', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_contracts', 'jobs_enabled')) {
                $t->boolean('jobs_enabled')->default(false);
                $t->string('jobs_service_item', 191)->nullable();
                $t->string('jobs_job_type', 191)->nullable();
                $t->unsignedInteger('jobs_technician_id')->nullable();
                $t->string('jobs_frequency', 20)->default('weekly');
                $t->unsignedSmallInteger('jobs_interval')->default(1);
                $t->unsignedSmallInteger('jobs_count')->nullable();
                $t->string('jobs_day_mode', 10)->default('same');
                $t->unsignedTinyInteger('jobs_day')->nullable();
                $t->string('jobs_time', 5)->nullable();
                $t->unsignedTinyInteger('jobs_lead_days')->default(7);
                $t->date('next_job_date')->nullable();
            }
        });

        Schema::table('service_jobs', function (Blueprint $t) {
            if (! Schema::hasColumn('service_jobs', 'sales_contract_id')) {
                $t->unsignedInteger('sales_contract_id')->nullable()->index();
                $t->unsignedSmallInteger('contract_occurrence')->nullable();
            }
        });

        $keys = [
            'Contract_Service_Jobs' => ['Service jobs (visits)', 'Trabajos de servicio (visitas)'],
            'Contract_Jobs_Switch' => ['Create service jobs automatically', 'Crear trabajos de servicio automáticamente'],
        ];
        foreach ($keys as $key => [$en, $es]) {
            if (! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        forget_db_translations();
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $t) {
            if (Schema::hasColumn('service_jobs', 'sales_contract_id')) {
                $t->dropIndex(['sales_contract_id']);
                $t->dropColumn(['sales_contract_id', 'contract_occurrence']);
            }
        });
        Schema::table('sales_contracts', function (Blueprint $t) {
            if (Schema::hasColumn('sales_contracts', 'jobs_enabled')) {
                $t->dropColumn(['jobs_enabled', 'jobs_service_item', 'jobs_job_type', 'jobs_technician_id', 'jobs_frequency', 'jobs_interval',
                    'jobs_count', 'jobs_day_mode', 'jobs_day', 'jobs_time', 'jobs_lead_days', 'next_job_date']);
            }
        });
        DB::table('translations')->whereIn('key', ['Contract_Service_Jobs', 'Contract_Jobs_Switch'])->delete();
        forget_db_translations();
    }
};
