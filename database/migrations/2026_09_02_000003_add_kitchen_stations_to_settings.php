<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kitchen stations: settings.kitchen_stations holds a JSON list of
     * [{id, name, category_ids: []}] used by the Kitchen Display to filter
     * ticket items per prep station (grill, fryer, drinks…). Null = no stations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'kitchen_stations')) {
                $table->json('kitchen_stations')->nullable();
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('kitchen_stations');
        });
    }
};
