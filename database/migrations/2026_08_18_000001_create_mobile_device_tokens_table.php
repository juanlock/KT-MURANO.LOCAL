<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FCM registration tokens for the mobile admin app. One row per
     * installed device; upserted by token on every app start so a token
     * that Firebase rotates simply replaces its old row.
     */
    public function up(): void
    {
        if (Schema::hasTable('mobile_device_tokens')) {
            return;
        }

        Schema::create('mobile_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token', 255)->unique();
            $table->string('platform', 20)->nullable();      // android | ios
            $table->string('device_name', 191)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_device_tokens');
    }
};
