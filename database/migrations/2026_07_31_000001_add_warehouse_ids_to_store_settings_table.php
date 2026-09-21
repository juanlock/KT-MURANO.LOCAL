<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            // Warehouses the online store sells from. NULL = all warehouses.
            $table->json('warehouse_ids')->nullable()->after('default_warehouse_id');
        });

        // Existing stores keep their current behaviour: sell from the single
        // default warehouse until the admin widens the selection.
        $defaultId = DB::table('store_settings')->value('default_warehouse_id');
        if ($defaultId) {
            DB::table('store_settings')->update([
                'warehouse_ids' => json_encode([(int) $defaultId]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn('warehouse_ids');
        });
    }
};
