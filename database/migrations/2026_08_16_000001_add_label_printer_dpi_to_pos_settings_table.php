<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            // Printhead resolution (203 or 300 dpi). TSPL coordinates are in
            // dots, so generating at 203 for a 300 dpi head prints everything
            // at two-thirds scale anchored top-left (diagnosed from a framed
            // test label). SIZE/GAP are in mm and unaffected.
            if (! Schema::hasColumn('pos_settings', 'label_printer_dpi')) {
                $table->unsignedSmallInteger('label_printer_dpi')->default(203);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (Schema::hasColumn('pos_settings', 'label_printer_dpi')) {
                $table->dropColumn('label_printer_dpi');
            }
        });
    }
};
