<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which shipping methods a product may be sent by.
     *
     * A product with NO rows here has no restriction — every method that
     * reaches the destination is offered, which is the existing behaviour and
     * therefore what every current product keeps.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_shipping_method')) {
            return;
        }

        Schema::create('product_shipping_method', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('product_id');
            $table->unsignedBigInteger('shipping_method_id');
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('shipping_method_id')->references('id')->on('shipping_methods')->cascadeOnDelete();

            $table->unique(['product_id', 'shipping_method_id'], 'product_shipping_method_unique');
            $table->index(['shipping_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_shipping_method');
    }
};
