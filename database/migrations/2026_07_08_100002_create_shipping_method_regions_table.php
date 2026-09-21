<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shipping method with NO region rows is available everywhere.
        // With region rows, it is available only for the listed countries.
        Schema::create('shipping_method_regions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shipping_method_id');
            $table->string('country', 100);
            $table->timestamps();

            $table->foreign('shipping_method_id')
                ->references('id')->on('shipping_methods')
                ->cascadeOnDelete();

            $table->index(['shipping_method_id']);
            $table->index(['country']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_regions');
    }
};
