<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_records')) {
            return;
        }
        // Registry of rows created by the Demo Data generator (System Settings).
        // Reset deletes exactly these rows and nothing else, so demo data can
        // coexist with real data.
        Schema::create('demo_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_type', 40);
            $table->unsignedBigInteger('record_id');
            $table->timestamps();
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_records');
    }
};
