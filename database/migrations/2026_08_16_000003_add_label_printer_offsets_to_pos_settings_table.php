<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            // Fine print-position adjustment (TSPL REFERENCE / SHIFT), the
            // standard knobs every label driver exposes. Needed e.g. when the
            // die-cut sticker is centered on a wider liner: the print origin
            // sits at the liner edge, so content must move ~2.5 mm sideways
            // to land centered on the sticker.
            if (! Schema::hasColumn('pos_settings', 'label_printer_offset_x_mm')) {
                $table->decimal('label_printer_offset_x_mm', 4, 1)->default(0);
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_offset_y_mm')) {
                $table->decimal('label_printer_offset_y_mm', 4, 1)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            foreach (['label_printer_offset_x_mm', 'label_printer_offset_y_mm'] as $column) {
                if (Schema::hasColumn('pos_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
