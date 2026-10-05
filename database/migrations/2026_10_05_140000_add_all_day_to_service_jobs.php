<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service job scheduling: a job is on one day or a range of days
 * (scheduled_date .. scheduled_end_date), at a time or ALL DAY (no hour --
 * listed as that day's task on the jobs list). all_day = no time chosen.
 * Contract visits with no visit time are all-day too (jobs_time nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_jobs', 'all_day')) {
            Schema::table('service_jobs', function (Blueprint $t) {
                $t->boolean('all_day')->default(false)->after('scheduled_end_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_jobs', 'all_day')) {
            Schema::table('service_jobs', fn (Blueprint $t) => $t->dropColumn('all_day'));
        }
    }
};
