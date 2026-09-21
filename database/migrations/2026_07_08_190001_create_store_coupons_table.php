<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_coupons', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('code', 60)->unique();
            $table->string('description', 190)->nullable();
            $table->string('type', 20)->default('percentage'); // percentage | fixed
            $table->decimal('value', 15, 2)->default(0);
            $table->decimal('min_order_amount', 15, 2)->nullable();
            $table->decimal('max_discount', 15, 2)->nullable(); // cap for percentage coupons
            $table->unsignedInteger('usage_limit')->nullable();  // total redemptions allowed
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_coupons');
    }
};
