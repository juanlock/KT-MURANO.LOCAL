<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal points ledger. Existing sale-based earning stays as-is (running
     * balance on clients.points); this table records reward redemptions and
     * manual admin adjustments so history/activity can be shown.
     */
    public function up(): void
    {
        Schema::create('loyalty_point_transactions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('client_id');
            $table->string('type', 20);  // earn | redeem | adjustment | reversed
            $table->decimal('points', 15, 2)->default(0);        // signed: + credit, - debit
            $table->decimal('balance_after', 15, 2)->default(0);
            // sale | pos_sale | reward | adjustment | registration | review | manual
            $table->string('source', 30)->default('adjustment');
            $table->string('reference_type', 60)->nullable();
            $table->integer('reference_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'type']);
            $table->index(['source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_transactions');
    }
};
