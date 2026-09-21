<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Display-only dispatch marker: records which warehouse a completed kitchen order
     * was handed off to. No stock movement is performed — this is purely informational.
     */
    public function up(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('kitchen_orders', 'dispatched_warehouse_id')) {
                $table->integer('dispatched_warehouse_id')->nullable()->after('warehouse_id');
            }
            if (! Schema::hasColumn('kitchen_orders', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('completed_at');
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->dropColumn(['dispatched_warehouse_id', 'dispatched_at']);
        });
    }
};
