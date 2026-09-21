<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product size guides (size charts). Each guide holds a flexible table:
     * `columns` = header labels (e.g. Size, Bust, Waist, Hips),
     * `rows` = array of rows, each an array of cell values aligned to columns.
     */
    public function up(): void
    {
        Schema::create('size_guides', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('name', 191);
            $table->string('image', 191)->nullable();
            $table->json('columns')->nullable();
            $table->json('rows')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('size_guides');
    }
};
