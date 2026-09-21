<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Google Sheets: opt-in nightly snapshot export of all tabs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_sheet_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('google_sheet_settings', 'auto_export')) {
                $table->boolean('auto_export')->default(false)->after('spreadsheet_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('google_sheet_settings', function (Blueprint $table) {
            if (Schema::hasColumn('google_sheet_settings', 'auto_export')) {
                $table->dropColumn('auto_export');
            }
        });
    }
};
