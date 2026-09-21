<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pickup orders: collected at a branch instead of shipped. `warehouse_id`
 * still drives stock/fulfilment; `pickup_branch_id` records the shopper's
 * explicit choice so a later warehouse re-pick can't silently move it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->string('delivery_method', 20)->default('ship')->after('shipping_method_name');
            $table->integer('pickup_branch_id')->unsigned()->nullable()->after('delivery_method');
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_method', 'pickup_branch_id']);
        });
    }
};
