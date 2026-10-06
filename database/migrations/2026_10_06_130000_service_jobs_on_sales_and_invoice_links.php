<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service jobs <-> invoices (user's request, 2026-10-06):
 *  - settings.sale_service_jobs_enabled: Defaults -> Sales -> "Service jobs on
 *    sales" (create / link service jobs from a sale, link invoices on a job).
 *    Off by default -- on only for Ruba (company 2) -- so companies that don't
 *    use service jobs (e.g. NewEra) never see the options.
 *  - service_job_sales: which invoices bill which jobs (a job can be billed by
 *    several invoices; one sale can create / link several jobs). Filled with
 *    the existing service_jobs.sale_id links.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'sale_service_jobs_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('sale_service_jobs_enabled')->default(false);
            });
        }
        DB::table('settings')->where('company_id', 2)->update(['sale_service_jobs_enabled' => true]);

        if (! Schema::hasTable('service_job_sales')) {
            Schema::create('service_job_sales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('service_job_id')->index();
                $table->unsignedBigInteger('sale_id')->index();
                $table->timestamps();
                $table->unique(['service_job_id', 'sale_id']);
            });
        }
        foreach (DB::table('service_jobs')->whereNotNull('sale_id')->get(['id', 'sale_id']) as $j) {
            DB::table('service_job_sales')->insertOrIgnore(['service_job_id' => $j->id, 'sale_id' => $j->sale_id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_job_sales');
        if (Schema::hasColumn('settings', 'sale_service_jobs_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('sale_service_jobs_enabled');
            });
        }
    }
};
