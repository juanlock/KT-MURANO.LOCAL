<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'export_settings')) {
                // JSON blob of global list/report export defaults
                // (scope 'all'|'page', totals, pdf_orientation
                // 'landscape'|'portrait', filename_date, pdf_meta).
                // Null = built-in defaults in resources/src/lib/exporters.js.
                $table->longText('export_settings')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'export_settings')) {
                $table->dropColumn('export_settings');
            }
        });
    }
};
