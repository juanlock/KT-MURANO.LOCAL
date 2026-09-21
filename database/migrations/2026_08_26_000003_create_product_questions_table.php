<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer questions about a product ("Ask About This Item"), answered by
     * the store. Only signed-in customers can ask; a question becomes public
     * once it is published (answering publishes it).
     */
    public function up(): void
    {
        if (Schema::hasTable('product_questions')) {
            return;
        }

        Schema::create('product_questions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('product_id');
            $table->integer('client_id');
            $table->string('asker_name', 150)->nullable();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->integer('answered_by')->nullable();   // users.id
            $table->dateTime('answered_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending|published|rejected
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->index(['product_id', 'status']);
            $table->index(['client_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_questions');
    }
};
