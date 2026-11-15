<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change history (user's request, 2026-11-15): which code file made each change (source_file, "app/...php:line") and
 * which git commit the server was running (code_commit). On audit_logs and its archive (same structure). Safe twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['audit_logs', 'audit_logs_archive'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'source_file')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('source_file', 255)->nullable()->after('url');
                    $t->string('code_commit', 40)->nullable()->after('source_file');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'audit_logs_archive'] as $table) {
            if (Schema::hasColumn($table, 'source_file')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['source_file', 'code_commit']));
            }
        }
    }
};
