<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'barcode_label_settings')) {
                // JSON blob of global barcode-label print defaults
                // (template, show_name/price/barcode/barcode_number,
                // barcode_height, font_size, bold_font, paper_size,
                // custom sticker dimensions, auto_print). Null = built-in
                // defaults in resources/src/pages/products/Barcode.vue.
                $table->longText('barcode_label_settings')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'barcode_label_settings')) {
                $table->dropColumn('barcode_label_settings');
            }
        });
    }
};
