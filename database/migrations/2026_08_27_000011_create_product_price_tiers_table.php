<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_price_tiers — quantity-break (wholesale) prices per product.
 *
 * One row is one bracket: "min_qty..max_qty pieces cost `price` each".
 * `max_qty` NULL means the open-ended top tier ("100+"). Prices are stored in
 * the same basis as products.price (base sale unit, before unit conversion,
 * product discount and tax), so a tier price REPLACES the retail base price
 * and then runs through the exact same discount/tax pipeline everywhere.
 *
 * The table is opt-in twice over: the global `settings.enable_wholesale_pricing`
 * switch, and a product having no rows at all — either way pricing falls back
 * to the plain retail price.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_price_tiers')) {
            return;
        }

        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            $table->unsignedInteger('product_id');
            $table->double('min_qty')->default(1);
            $table->double('max_qty')->nullable();
            $table->double('price')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('product_id', 'ppt_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
    }
};
