<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('wallet_id');
            $table->string('type', 10);   // credit | debit
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2)->default(0);
            // checkout | pos_sale | refund | withdrawal | adjustment | gift_card
            $table->string('source', 30)->default('adjustment');
            // Loose polymorphic reference to the originating record (order/sale/etc.)
            $table->string('reference_type', 60)->nullable();
            $table->integer('reference_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->integer('created_by')->nullable(); // users.id (admin/cashier), null = customer/system
            $table->timestamps();

            $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
            $table->index(['wallet_id', 'type']);
            $table->index(['source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
