<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings -> Invoice PDF -> Default: "Hide the time" -- the invoice shows
 * only the date (2025-12-30), not the time it was made (16:39:00).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'sale_pdf_hide_time')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('sale_pdf_hide_time')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'sale_pdf_hide_time')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('sale_pdf_hide_time'));
        }
    }
};
