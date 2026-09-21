<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_popups', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('title', 150)->nullable();
            $table->text('message')->nullable();
            $table->string('type', 20)->default('announcement'); // announcement | subscription | sale
            $table->string('image', 255)->nullable();
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_url', 255)->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('trigger', 20)->default('delay'); // immediate | delay | exit
            $table->unsignedInteger('delay_seconds')->default(3);
            $table->string('frequency', 20)->default('session'); // once | session | always
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['enabled']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_popups');
    }
};
