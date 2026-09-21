<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salla integration (single store per tenant):
 *  - salla_settings: singleton row — per-tenant partner-app credentials, the
 *    OAuth token pair (Salla: access 14 days, refresh 1 month & SINGLE-USE),
 *    connected store metadata and the webhook signing secret.
 *  - salla_mappings: local entity <-> Salla id (no store column: one store).
 *  - salla_logs: sync/webhook audit trail, same shape as shopify_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salla_settings')) {
            Schema::create('salla_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                // Salla Partners app credentials (per tenant, like QuickBooks).
                $table->string('client_id', 191)->nullable();
                $table->text('client_secret')->nullable();
                // OAuth tokens (write-only through the API).
                $table->longText('access_token')->nullable();
                $table->longText('refresh_token')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                $table->timestamp('refresh_token_expires_at')->nullable();
                // Connected store metadata (GET store/info).
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('store_name', 191)->nullable();
                $table->string('store_domain', 191)->nullable();
                // Webhook signature secret from the Salla Partners portal.
                $table->text('webhook_secret')->nullable();
                // null = aggregate stock across all warehouses.
                $table->integer('warehouse_id')->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('salla_mappings')) {
            Schema::create('salla_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 32); // product|variant|customer|order
                $table->unsignedBigInteger('local_id');
                $table->unsignedBigInteger('salla_id');
                $table->json('extra')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps(6);
                $table->unique(['entity_type', 'local_id'], 'salla_mappings_type_local_unique');
                $table->index(['entity_type', 'salla_id'], 'salla_mappings_type_remote_idx');
            });
        }

        if (! Schema::hasTable('salla_logs')) {
            Schema::create('salla_logs', function (Blueprint $table) {
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
        Schema::dropIfExists('salla_logs');
        Schema::dropIfExists('salla_mappings');
        Schema::dropIfExists('salla_settings');
    }
};
