<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PrestaShop integration (single store per tenant):
 *  - prestashop_settings: singleton row — store URL + webservice key (Basic
 *    auth; no OAuth in PrestaShop), default shop language for multilang
 *    fields, and the stock warehouse scope.
 *  - prestashop_mappings: local entity <-> PrestaShop id.
 *  - prestashop_logs: sync audit trail, same shape as salla_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prestashop_settings')) {
            Schema::create('prestashop_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                // https://store.example.com — the /api suffix is added by the client.
                $table->string('store_url', 191)->nullable();
                // Webservice key from Advanced Parameters -> Webservice.
                $table->text('api_key')->nullable();
                // Language id used for multilang fields (name, link_rewrite).
                $table->unsignedInteger('default_language_id')->default(1);
                // null = aggregate stock across all warehouses.
                $table->integer('warehouse_id')->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('prestashop_mappings')) {
            Schema::create('prestashop_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 32); // product|customer|order
                $table->unsignedBigInteger('local_id');
                $table->unsignedBigInteger('prestashop_id');
                $table->json('extra')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps(6);
                $table->unique(['entity_type', 'local_id'], 'ps_mappings_type_local_unique');
                $table->index(['entity_type', 'prestashop_id'], 'ps_mappings_type_remote_idx');
            });
        }

        if (! Schema::hasTable('prestashop_logs')) {
            Schema::create('prestashop_logs', function (Blueprint $table) {
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
        Schema::dropIfExists('prestashop_logs');
        Schema::dropIfExists('prestashop_mappings');
        Schema::dropIfExists('prestashop_settings');
    }
};
