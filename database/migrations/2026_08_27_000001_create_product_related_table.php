<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hand-picked "Related products" for a product page.
     *
     * Empty for a product = fall back to the automatic rule (same category,
     * then newest), so nothing changes until an admin curates a list.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_related')) {
            return;
        }

        Schema::create('product_related', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('product_id');
            $table->integer('related_product_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('related_product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->unique(['product_id', 'related_product_id'], 'product_related_unique');
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_related');
    }
};
