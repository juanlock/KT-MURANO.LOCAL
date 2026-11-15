<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * kt_logs.attachments (user's request, 2026-11-15): the files a request produced (a report, an export...) travel with its
 * row -- a JSON list of paths relative to public/ (files/company_999/kt-logs/{id}/...), served only to the SuperAdmin on
 * AdminSystem by KtLogController::attachment. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kt_logs') && ! Schema::hasColumn('kt_logs', 'attachments')) {
            Schema::table('kt_logs', function (Blueprint $t) {
                $t->text('attachments')->nullable()->after('files_modified');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('kt_logs', 'attachments')) {
            Schema::table('kt_logs', fn (Blueprint $t) => $t->dropColumn('attachments'));
        }
    }
};
