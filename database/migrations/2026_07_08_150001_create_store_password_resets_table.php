<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dedicated reset tokens for online-store customers, kept separate from
        // the admin/web password_reset_tokens table to avoid email collisions.
        Schema::create('store_password_resets', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token', 190);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_password_resets');
    }
};
