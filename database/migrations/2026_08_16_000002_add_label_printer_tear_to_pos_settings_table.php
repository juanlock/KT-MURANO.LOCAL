<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            // TSPL SET TEAR ON feeds the label to the tear bar after each job.
            // Proper printers back-feed before the next print; many clones do
            // not, so every following label starts 15-20 mm too far in —
            // content shifted down / split across stickers. Off by default;
            // jobs send SET TEAR OFF explicitly because the printer stores
            // the last state.
            if (! Schema::hasColumn('pos_settings', 'label_printer_tear')) {
                $table->boolean('label_printer_tear')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (Schema::hasColumn('pos_settings', 'label_printer_tear')) {
                $table->dropColumn('label_printer_tear');
            }
        });
    }
};
