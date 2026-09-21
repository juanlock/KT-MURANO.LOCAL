<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item bump: item_states holds {sale_detail_id: true} for lines the kitchen
     * has finished. Items themselves still come from sale_details at read time, so
     * a sale edited after being sent self-heals (unknown ids are simply ignored).
     */
    public function up(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('kitchen_orders', 'item_states')) {
                $table->json('item_states')->nullable()->after('instructions');
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->dropColumn('item_states');
        });
    }
};
