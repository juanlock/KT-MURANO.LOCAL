<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_reward_redemptions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('reward_id');
            $table->integer('client_id');
            $table->string('reward_name', 150);           // snapshot
            $table->string('reward_type', 20);
            $table->decimal('points_spent', 15, 2)->default(0);
            $table->string('status', 20)->default('issued'); // issued | fulfilled | cancelled
            $table->string('channel', 20)->default('storefront'); // storefront | pos | admin
            $table->string('reference_type', 60)->nullable(); // GiftCard / StoreCoupon class
            $table->integer('reference_id')->nullable();
            $table->string('code', 60)->nullable();        // issued gift card / coupon code
            $table->string('note', 255)->nullable();
            $table->integer('created_by')->nullable();     // users.id (cashier/admin), null = customer
            $table->integer('fulfilled_by')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->foreign('reward_id')->references('id')->on('loyalty_rewards')->cascadeOnDelete();
            $table->index(['client_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reward_redemptions');
    }
};
