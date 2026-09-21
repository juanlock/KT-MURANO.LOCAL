<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_coupon_redemptions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('coupon_id');
            $table->integer('client_id')->nullable();
            $table->integer('online_order_id')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->timestamps();

            $table->foreign('coupon_id')->references('id')->on('store_coupons')->cascadeOnDelete();
            $table->index(['coupon_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_coupon_redemptions');
    }
};
