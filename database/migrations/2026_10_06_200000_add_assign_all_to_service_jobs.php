<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Assign to: All operators" (user's request, 2026-10-06): a crew job -- it
 * shows in Daily Tasks for every operator of the company (and the managers)
 * and any of them can start / complete it. Its hours count for each hourly
 * operator's payroll and its labor cost = hours x the operators' rates.
 * technician_id stays null on such a job.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_jobs', 'assign_all')) {
            Schema::table('service_jobs', fn (Blueprint $t) => $t->boolean('assign_all')->default(false)->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_jobs', 'assign_all')) {
            Schema::table('service_jobs', fn (Blueprint $t) => $t->dropColumn('assign_all'));
        }
    }
};
