<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumia (Seller Center API) integration:
 *  - jumia_settings: singleton row — the region API URL, the seller account
 *    email (UserID) and the API key that signs every request. No OAuth.
 *  - jumia_mappings: local entity <-> Jumia id (orders now; products later).
 *  - jumia_logs: sync audit trail, same shape as salla_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jumia_settings')) {
            Schema::create('jumia_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                // Region endpoint, e.g. https://sellercenter-api.jumia.com.ng
                $table->string('api_url', 191)->nullable();
                // Seller Center account email = the API "UserID" parameter.
                $table->string('user_email', 191)->nullable();
                $table->text('api_key')->nullable();
                // null = aggregate stock across all warehouses.
                $table->integer('warehouse_id')->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('jumia_mappings')) {
            Schema::create('jumia_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 32); // order|product
                $table->unsignedBigInteger('local_id');
                $table->unsignedBigInteger('jumia_id');
                $table->json('extra')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps(6);
                $table->unique(['entity_type', 'local_id'], 'jumia_mappings_type_local_unique');
                $table->index(['entity_type', 'jumia_id'], 'jumia_mappings_type_remote_idx');
            });
        }

        if (! Schema::hasTable('jumia_logs')) {
            Schema::create('jumia_logs', function (Blueprint $table) {
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
        Schema::dropIfExists('jumia_logs');
        Schema::dropIfExists('jumia_mappings');
        Schema::dropIfExists('jumia_settings');
    }
};
