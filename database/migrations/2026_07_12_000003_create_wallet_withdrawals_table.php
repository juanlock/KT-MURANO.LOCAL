<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_withdrawals', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('wallet_id');
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | paid
            $table->string('method', 60)->nullable();         // bank_transfer | cash | ...
            $table->string('destination', 190)->nullable();   // account no / notes provided by customer
            $table->string('note', 255)->nullable();          // admin note on approve/reject
            $table->integer('processed_by')->nullable();       // users.id
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_withdrawals');
    }
};
