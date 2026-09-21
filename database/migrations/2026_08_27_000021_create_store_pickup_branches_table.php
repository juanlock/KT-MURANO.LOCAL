<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches the storefront offers for order collection. One row per warehouse
 * the admin opts in; the warehouse still owns the stock, this only adds the
 * shopper-facing address / hours / contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_pickup_branches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('warehouse_id')->unsigned();
            $table->string('address', 255)->nullable();
            $table->string('hours', 191)->nullable();
            $table->string('contact', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_pickup_branches');
    }
};
