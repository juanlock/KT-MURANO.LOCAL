<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_pages')) {
            return;
        }

        Schema::create('store_pages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id', true);

            $table->string('title', 190);
            $table->string('slug', 190)->unique();
            $table->longText('content')->nullable();
            $table->string('seo_title', 190)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->boolean('published')->default(true);

            $table->timestamps();

            $table->index(['published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_pages');
    }
};
