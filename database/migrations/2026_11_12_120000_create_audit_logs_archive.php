<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change history archive (user's rule, 2026-11-12: nothing is deleted): audit_logs keeps the last 6 months and
 * `audit:archive` moves older rows here, same structure, kept for good. Settings -> Historial de cambios reads it with
 * "Archivo". Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs') && ! Schema::hasTable('audit_logs_archive')) {
            DB::statement('CREATE TABLE `audit_logs_archive` LIKE `audit_logs`');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs_archive');
    }
};
