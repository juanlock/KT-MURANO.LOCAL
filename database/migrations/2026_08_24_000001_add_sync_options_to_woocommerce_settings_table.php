<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync tuning (batch sizes, timeouts, retries, lookup caps) used to live in
 * WOO_* env vars, so changing one meant editing .env over SSH. Store them next
 * to the credentials instead, as a single JSON blob, so the WooCommerce
 * settings screen can edit them. Null/absent keys still fall back to env.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('woocommerce_settings')) {
            return;
        }
        if (Schema::hasColumn('woocommerce_settings', 'sync_options')) {
            return;
        }

        Schema::table('woocommerce_settings', function (Blueprint $table) {
            $table->json('sync_options')->nullable()->after('wp_app_password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('woocommerce_settings')) {
            return;
        }
        if (! Schema::hasColumn('woocommerce_settings', 'sync_options')) {
            return;
        }

        Schema::table('woocommerce_settings', function (Blueprint $table) {
            $table->dropColumn('sync_options');
        });
    }
};
