<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('product_id');
            $table->integer('client_id');
            $table->integer('online_order_id')->nullable();
            $table->string('reviewer_name', 150)->nullable();
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('online_order_id')->references('id')->on('online_orders')->nullOnDelete();

            // One review per customer per product.
            $table->unique(['client_id', 'product_id'], 'product_reviews_client_product_unique');
            $table->index(['product_id', 'status']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
