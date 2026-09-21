<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram integration: a singleton settings row holding the bot token, the
 * destination chat, and the subscribed event list (null/[] = ALL events).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_settings')) {
            Schema::create('telegram_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                // Bot token from @BotFather.
                $table->string('bot_token', 191)->nullable();
                // Chat/group/channel id the bot posts into (may be negative).
                $table->string('chat_id', 64)->nullable();
                $table->json('events')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_settings');
    }
};
