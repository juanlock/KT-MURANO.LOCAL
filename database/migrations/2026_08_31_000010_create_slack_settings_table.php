<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slack integration: a singleton settings row holding the incoming-webhook URL
 * and the subscribed event list. An empty/null `events` list means ALL events.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('slack_settings')) {
            Schema::create('slack_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                // Slack incoming-webhook URL (https://hooks.slack.com/services/…).
                $table->text('webhook_url')->nullable();
                // Canonical event names (WebhookService::availableEvents());
                // null or [] subscribes to everything.
                $table->json('events')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_settings');
    }
};
