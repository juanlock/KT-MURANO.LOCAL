<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_settings', 'label_printer_render_mode')) {
                // How the label is drawn:
                // 'native' = TSPL TEXT/BARCODE commands, rendered by the printer
                //            (fastest, sharpest bars, printer fonts only).
                // 'raster' = the browser renders the label design to a 203 dpi
                //            image which is sent as a TSPL BITMAP, so the print
                //            matches the on-screen preview exactly.
                $table->string('label_printer_render_mode', 16)->nullable()->default('native');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (Schema::hasColumn('pos_settings', 'label_printer_render_mode')) {
                $table->dropColumn('label_printer_render_mode');
            }
        });
    }
};
