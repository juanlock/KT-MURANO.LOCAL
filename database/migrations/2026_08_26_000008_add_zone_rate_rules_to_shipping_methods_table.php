<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn a shipping method into a zone RATE RULE.
     *
     * Deliberately extends this table rather than adding a parallel one:
     * online_orders.shipping_method_id has a foreign key here, and
     * product_shipping_method references it too, so a separate rates table
     * could never be stored on an order.
     *
     * zone_id NULL keeps the legacy behaviour exactly — a flat price filtered
     * by shipping_method_regions — so every existing method is untouched.
     */
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('shipping_methods', 'shipping_zone_id')) {
                $table->unsignedBigInteger('shipping_zone_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('shipping_methods', 'rate_type')) {
                // flat | price | weight — what min_value/max_value are measured in.
                $table->string('rate_type', 20)->default('flat')->after('price');
            }
            if (! Schema::hasColumn('shipping_methods', 'min_value')) {
                $table->decimal('min_value', 15, 3)->nullable()->after('rate_type');
            }
            if (! Schema::hasColumn('shipping_methods', 'max_value')) {
                $table->decimal('max_value', 15, 3)->nullable()->after('min_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            foreach (['shipping_zone_id', 'rate_type', 'min_value', 'max_value'] as $col) {
                if (Schema::hasColumn('shipping_methods', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
