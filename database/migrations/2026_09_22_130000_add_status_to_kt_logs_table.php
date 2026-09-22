<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `status` column to kt_logs so each request can be marked
 * Completed / In Progress / Blocked -- surfaced by Protocol Preparate's
 * end-of-run report (the last request + what changed + its status).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kt_logs', 'status')) {
            Schema::table('kt_logs', function (Blueprint $table) {
                $table->string('status')->default('Completed')->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('kt_logs', 'status')) {
            Schema::table('kt_logs', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
