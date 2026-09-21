<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mailchimp integration:
 *  - mailchimp_settings: singleton row — API key (its "-usX" suffix names the
 *    datacenter), target audience (list), and the consent/auto-sync options.
 *  - mailchimp_logs: sync audit trail, same shape as salla_logs.
 * No mapping table: Mailchimp members are addressed by md5(lower(email)), so
 * upserts are naturally idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailchimp_settings')) {
            Schema::create('mailchimp_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                $table->text('api_key')->nullable();
                $table->string('list_id', 64)->nullable();
                $table->string('list_name', 191)->nullable();
                // New members join as 'pending' (Mailchimp sends a confirmation
                // email) instead of 'subscribed' — the GDPR-safe default.
                $table->boolean('double_opt_in')->default(true);
                // Push new Stocky customers automatically (client.created event).
                $table->boolean('auto_sync')->default(false);
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mailchimp_logs')) {
            Schema::create('mailchimp_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action', 100);
                $table->string('level', 20)->default('info'); // info|warning|error
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps(6);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mailchimp_logs');
        Schema::dropIfExists('mailchimp_settings');
    }
};
