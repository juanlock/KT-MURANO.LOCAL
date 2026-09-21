<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flutterwave v3 payment gateway for the Online Store (same flow as the
 * Stocky SaaS FlutterwaveGateway): keys + webhook Secret Hash on
 * store_settings (test vs live decided by the FLWPUBK_TEST/FLWSECK_TEST
 * key pair), and the tx_ref / transaction id on online_orders (the tx_ref
 * is how the verify redirect and webhooks find the pending order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'flutterwave_enabled')) {
                $table->boolean('flutterwave_enabled')->default(0);
            }
            if (! Schema::hasColumn('store_settings', 'flutterwave_public_key')) {
                $table->string('flutterwave_public_key', 191)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'flutterwave_secret_key')) {
                $table->text('flutterwave_secret_key')->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'flutterwave_secret_hash')) {
                $table->string('flutterwave_secret_hash', 191)->nullable();
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'flutterwave_tx_ref')) {
                $table->string('flutterwave_tx_ref', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('online_orders', 'flutterwave_transaction_id')) {
                $table->string('flutterwave_transaction_id', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            foreach (['flutterwave_secret_hash', 'flutterwave_secret_key', 'flutterwave_public_key', 'flutterwave_enabled'] as $col) {
                if (Schema::hasColumn('store_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            foreach (['flutterwave_transaction_id', 'flutterwave_tx_ref'] as $col) {
                if (Schema::hasColumn('online_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
