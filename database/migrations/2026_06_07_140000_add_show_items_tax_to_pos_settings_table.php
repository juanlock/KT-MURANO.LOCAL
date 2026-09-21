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
            if (! Schema::hasColumn('pos_settings', 'show_items_tax')) {
                // Controls ONLY the Total Items Tax summary row on the
                // create / edit / detail sale screens (not printed receipts/PDF).
                // Managed from System Settings → Defaults.
                $table->boolean('show_items_tax')->default(0)->after('show_logo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (Schema::hasColumn('pos_settings', 'show_items_tax')) {
                $table->dropColumn('show_items_tax');
            }
        });
    }
};
