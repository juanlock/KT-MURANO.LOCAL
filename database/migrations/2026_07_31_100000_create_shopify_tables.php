<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify Integration.
 *
 * The existing WooCommerce integration stores the remote id in a column on each
 * local table (products.woocommerce_id, clients.woocommerce_id, ...). That works
 * for exactly one store and cannot be extended: the same product has a different
 * id in every Shopify shop it is published to.
 *
 * So the mapping lives in `shopify_links` instead — one row per
 * (store, entity type, local record), holding the remote id. Connecting a second
 * store adds rows; it never fights the first store for a column. It also means
 * this module adds nothing to the existing tables, so nothing already working
 * can break.
 */
return new class extends Migration
{
    public function up()
    {
        // --- the shops themselves --------------------------------------------
        Schema::create('shopify_stores', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('shop_domain')->unique();          // acme.myshopify.com
            $table->text('access_token');                     // Admin API access token
            $table->string('api_version', 20)->default('2024-10');
            $table->string('webhook_secret')->nullable();     // for HMAC verification

            // Where this shop's stock and orders land in the ERP.
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('location_id')->nullable();        // Shopify inventory location
            $table->string('currency', 10)->nullable();
            $table->string('shop_name')->nullable();          // as reported by Shopify
            $table->string('shop_email')->nullable();

            // connected | error | disconnected
            $table->string('status', 20)->default('disconnected');
            $table->text('last_error')->nullable();
            $table->dateTime('last_connected_at')->nullable();

            // Which price column feeds Shopify, and whether pulls may create.
            $table->string('price_field', 30)->default('price');
            $table->boolean('create_missing_products')->default(true);
            $table->boolean('create_missing_customers')->default(true);
            $table->boolean('auto_sync')->default(false);
            $table->unsignedInteger('sync_interval_minutes')->default(60);

            // Per-entity opt-in. A shop you only pull orders from should not have
            // a stray product push quietly rewriting its catalogue.
            $table->boolean('sync_products')->default(true);
            $table->boolean('sync_inventory')->default(true);
            $table->boolean('sync_customers')->default(true);
            $table->boolean('sync_orders')->default(true);
            $table->boolean('sync_collections')->default(true);
            $table->boolean('sync_fulfillments')->default(true);

            $table->dateTime('last_sync_at')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            $table->index('status');
        });

        // --- local record <-> remote record ----------------------------------
        Schema::create('shopify_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('store_id');
            // product | variant | inventory_item | customer | order | collection | fulfillment
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('local_id');
            $table->string('shopify_id', 64);
            $table->string('shopify_handle')->nullable();
            // Second remote id some entities need (a variant's inventory_item_id,
            // an order's fulfillment id) without inventing another table.
            $table->string('secondary_id', 64)->nullable();
            // Fingerprint of what we last pushed, so an unchanged record can be
            // skipped instead of burning an API call against the rate limit.
            $table->string('push_hash', 64)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'entity_type', 'local_id'], 'shopify_links_local_unique');
            $table->unique(['store_id', 'entity_type', 'shopify_id'], 'shopify_links_remote_unique');
            $table->index(['store_id', 'entity_type']);
        });

        // --- one row per sync operation --------------------------------------
        Schema::create('shopify_sync_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('entity', 30);                     // products | inventory | ...
            $table->string('direction', 10);                  // push | pull
            $table->string('status', 20)->default('pending'); // pending|running|completed|failed|cancelled
            $table->boolean('dry_run')->default(false);

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('created_items')->default(0);
            $table->unsignedInteger('updated_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('percentage')->default(0);

            $table->string('stage')->nullable();
            $table->string('cursor')->nullable();             // Shopify page_info
            $table->text('last_error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->dateTime('heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'entity', 'status']);
            $table->index('status');
        });

        // --- audit trail ------------------------------------------------------
        Schema::create('shopify_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('entity', 30)->nullable();
            $table->string('action', 60);
            $table->string('level', 10)->default('info');     // info | warning | error
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'level']);
            $table->index('run_id');
            $table->index('created_at');
        });

        // --- inbound webhooks -------------------------------------------------
        Schema::create('shopify_webhook_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('topic', 60);
            // Shopify retries a webhook until it gets a 2xx, so the same event
            // arrives more than once. This id is what makes handling idempotent.
            $table->string('event_id', 100)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending'); // pending|processed|failed|ignored
            $table->text('error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'event_id'], 'shopify_webhook_event_unique');
            $table->index(['store_id', 'topic', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('shopify_webhook_events');
        Schema::dropIfExists('shopify_logs');
        Schema::dropIfExists('shopify_sync_runs');
        Schema::dropIfExists('shopify_links');
        Schema::dropIfExists('shopify_stores');
    }
};
