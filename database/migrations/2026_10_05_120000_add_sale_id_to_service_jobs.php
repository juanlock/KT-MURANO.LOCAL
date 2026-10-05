<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Sale -> "Service job": a one-off sale can create its service job;
 * service_jobs.sale_id links it back (Sale Detail -> Service Jobs tab).
 * (Recurring sales create visits through their Sales Contract instead.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_jobs', 'sale_id')) {
            Schema::table('service_jobs', function (Blueprint $t) {
                $t->unsignedInteger('sale_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_jobs', 'sale_id')) {
            Schema::table('service_jobs', function (Blueprint $t) {
                $t->dropIndex(['sale_id']);
                $t->dropColumn('sale_id');
            });
        }
    }
};
