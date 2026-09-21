<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            // Physical size of one sticker on the roll, used for the TSPL SIZE
            // command. This must match the loaded labels exactly: it tells the
            // printer where to expect the gap, so a mismatch makes every
            // following label print further off-position (content drifting
            // across stickers, blank labels). Previously the on-screen sticker
            // size from the Print Barcode page was used, which defaults to
            // 50x25 and has no reason to match the physical roll.
            if (! Schema::hasColumn('pos_settings', 'label_printer_width_mm')) {
                $table->decimal('label_printer_width_mm', 5, 1)->nullable()->default(50);
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_height_mm')) {
                $table->decimal('label_printer_height_mm', 5, 1)->nullable()->default(30);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            foreach (['label_printer_width_mm', 'label_printer_height_mm'] as $column) {
                if (Schema::hasColumn('pos_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
