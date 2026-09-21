<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            if (! Schema::hasColumn('pos_settings', 'receipt_font_family')) {
                // CSS font-family stack for the printed POS receipt.
                // Null = keep the default fonts from /css/pos_print.css.
                $table->string('receipt_font_family', 120)->nullable()->after('receipt_paper_size');
            }
            if (! Schema::hasColumn('pos_settings', 'receipt_font_size')) {
                // Base font size (px) forced across the whole receipt when set.
                // Null = legacy sizes (12px at print time).
                $table->unsignedTinyInteger('receipt_font_size')->nullable()->after('receipt_font_family');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (Schema::hasColumn('pos_settings', 'receipt_font_size')) {
                $table->dropColumn('receipt_font_size');
            }
            if (Schema::hasColumn('pos_settings', 'receipt_font_family')) {
                $table->dropColumn('receipt_font_family');
            }
        });
    }
};
