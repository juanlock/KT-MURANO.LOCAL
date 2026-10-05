<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office -> Settings -> "Import all emails": the whole mailbox, in
 * batches, with progress that survives a page reload. full_import =
 * {status: running|paused|done|cancelled, folders: [{path, direction,
 * total, page, done}], imported, skipped, started_at, finished_at}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_office_settings', 'full_import')) {
            Schema::table('email_office_settings', function (Blueprint $table) {
                $table->json('full_import')->nullable()->after('last_stats');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_office_settings', 'full_import')) {
            Schema::table('email_office_settings', function (Blueprint $table) {
                $table->dropColumn('full_import');
            });
        }
    }
};
