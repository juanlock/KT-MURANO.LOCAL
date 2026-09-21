<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_quote_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('product_id')->nullable();
            $table->string('product_name', 190)->nullable();
            $table->integer('client_id')->nullable(); // set when the requester is logged in
            $table->string('name', 150);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new'); // new | handled | closed
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->index(['status']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_quote_requests');
    }
};
