<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kitchen_orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('sale_id')->index();
            $table->string('ref')->nullable();
            $table->integer('client_id')->nullable()->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->integer('user_id')->nullable();          // who sent it to the kitchen
            $table->integer('assigned_to')->nullable();      // optional assigned staff (users.id)
            $table->string('status')->default('pending');    // pending | preparing | completed | on_hold
            $table->text('instructions')->nullable();        // special instructions / notes
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kitchen_orders');
    }
};
