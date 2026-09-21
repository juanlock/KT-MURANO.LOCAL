<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_settings', 'label_printer_enabled')) {
                // Direct TSPL label printing (Print Barcode page). When enabled,
                // the Print button sends raw TSPL to the label printer instead
                // of opening the browser print dialog.
                $table->boolean('label_printer_enabled')->default(0);
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_connection')) {
                // 'windows' = USB printer via the Windows print spooler (RAW),
                // 'network' = TCP RAW/JetDirect (usually port 9100).
                $table->string('label_printer_connection', 16)->nullable()->default('windows');
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_name')) {
                // Exact Windows printer name (e.g. "4BARCODE 4B-2054L").
                $table->string('label_printer_name', 191)->nullable();
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_ip')) {
                $table->string('label_printer_ip', 64)->nullable();
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_port')) {
                $table->unsignedSmallInteger('label_printer_port')->nullable()->default(9100);
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_gap_mm')) {
                // Gap between labels on the roll.
                $table->decimal('label_printer_gap_mm', 4, 1)->default(2);
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_density')) {
                // TSPL DENSITY 0..15 (print darkness).
                $table->unsignedTinyInteger('label_printer_density')->default(8);
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_speed')) {
                // TSPL SPEED in inches/second (typical 2..6).
                $table->unsignedTinyInteger('label_printer_speed')->default(3);
            }
            if (! Schema::hasColumn('pos_settings', 'label_printer_direction')) {
                // TSPL DIRECTION 0|1 (flips output 180° for tear-off orientation).
                $table->unsignedTinyInteger('label_printer_direction')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            foreach ([
                'label_printer_enabled', 'label_printer_connection', 'label_printer_name',
                'label_printer_ip', 'label_printer_port', 'label_printer_gap_mm',
                'label_printer_density', 'label_printer_speed', 'label_printer_direction',
            ] as $column) {
                if (Schema::hasColumn('pos_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
