<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_order_return_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('return_id');
            $table->integer('online_order_item_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->integer('product_variant_id')->nullable();
            $table->decimal('qty', 12, 3)->default(1);
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            $table->foreign('return_id')->references('id')->on('online_order_returns')->cascadeOnDelete();
            $table->index(['return_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_return_items');
    }
};
