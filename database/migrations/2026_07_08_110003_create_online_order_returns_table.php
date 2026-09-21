<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_order_returns', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('order_id'); // matches online_orders.id (signed int)
            $table->integer('client_id')->nullable();

            // 'cancellation' (pre-fulfilment) or 'return' (post-delivery)
            $table->string('type', 20)->default('return');
            // requested → approved|rejected ; approved → refunded
            $table->string('status', 20)->default('requested');

            $table->text('reason')->nullable();
            $table->text('admin_note')->nullable();

            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reference', 190)->nullable(); // e.g. Stripe refund id
            $table->unsignedInteger('processed_by')->nullable();  // admin user id

            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('online_orders')->cascadeOnDelete();
            $table->index(['status']);
            $table->index(['type']);
            $table->index(['client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_returns');
    }
};
