<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopper-submitted proof of an offline payment (GCash / bank transfer):
 * screenshot + reference number + amount, reviewed by an admin. Approving a
 * proof is what marks the order paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_order_payment_proofs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('order_id');
            $table->string('payment_method', 40)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('paid_at')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending');
            $table->integer('reviewed_by')->unsigned()->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->foreign('order_id')->references('id')->on('online_orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_payment_proofs');
    }
};
