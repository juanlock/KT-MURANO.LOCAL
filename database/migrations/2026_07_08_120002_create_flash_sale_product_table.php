<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_product', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('flash_sale_id');
            $table->integer('product_id'); // matches products.id (signed int)
            $table->string('discount_type', 10)->default('percent'); // percent | fixed
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('flash_sale_id')->references('id')->on('flash_sales')->cascadeOnDelete();
            $table->index(['flash_sale_id']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_product');
    }
};
