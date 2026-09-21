<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Xero integration:
 *  - xero_settings: singleton row — per-tenant Xero app credentials, the OAuth
 *    token pair (Xero: access 30 min, refresh 60 days & ROTATING single-use),
 *    the connected Xero organisation (tenant) and account-code defaults.
 *  - xero_mappings: local entity <-> Xero id. Xero ids are UUID strings.
 *  - xero_logs: sync audit trail, same shape as salla_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('xero_settings')) {
            Schema::create('xero_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                $table->string('client_id', 191)->nullable();
                $table->text('client_secret')->nullable();
                $table->longText('access_token')->nullable();
                $table->longText('refresh_token')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                // Connected Xero organisation ("tenant" in Xero terms).
                $table->string('tenant_id', 64)->nullable();
                $table->string('tenant_name', 191)->nullable();
                // Revenue account for invoice lines ('200' = default Sales account).
                $table->string('sales_account_code', 20)->default('200');
                // Bank account for payments; empty = do not push payments.
                $table->string('payment_account_code', 20)->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('xero_mappings')) {
            Schema::create('xero_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 32); // contact|invoice|payment
                $table->unsignedBigInteger('local_id');
                $table->string('xero_id', 64);
                $table->json('extra')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps(6);
                $table->unique(['entity_type', 'local_id'], 'xero_mappings_type_local_unique');
                $table->index(['entity_type', 'xero_id'], 'xero_mappings_type_remote_idx');
            });
        }

        if (! Schema::hasTable('xero_logs')) {
            Schema::create('xero_logs', function (Blueprint $table) {
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
        Schema::dropIfExists('xero_logs');
        Schema::dropIfExists('xero_mappings');
        Schema::dropIfExists('xero_settings');
    }
};
