<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Loyalty items" — the catalog of rewards a customer can redeem points for.
     * type: gift_card (credits the e-wallet), voucher (issues a discount coupon),
     * product (a free product fulfilled by staff).
     */
    public function up(): void
    {
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('type', 20)->default('gift_card'); // gift_card | voucher | product
            $table->decimal('points_cost', 15, 2)->default(0);
            $table->decimal('value', 15, 2)->default(0);   // gift_card credit / voucher discount amount
            $table->integer('product_id')->nullable();      // for type=product
            $table->string('image', 191)->nullable();
            $table->boolean('active')->default(true);
            $table->integer('stock')->nullable();           // null = unlimited
            $table->integer('per_customer_limit')->nullable(); // null = unlimited
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rewards');
    }
};
