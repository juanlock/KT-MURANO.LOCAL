<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bKash (Tokenized Checkout) + SSLCommerz (hosted checkout) for the Online
 * Store — same redirect-then-verify pattern as the other store gateways:
 * credentials on store_settings, and the gateway payment/transaction ids on
 * online_orders (the indexed id is how the callback and IPN find the pending
 * order). Both charge in BDT; bKash is only offered while the active store
 * currency is BDT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'bkash_enabled')) {
                $table->boolean('bkash_enabled')->default(0);
            }
            if (! Schema::hasColumn('store_settings', 'bkash_app_key')) {
                $table->string('bkash_app_key', 191)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'bkash_app_secret')) {
                $table->text('bkash_app_secret')->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'bkash_username')) {
                $table->string('bkash_username', 191)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'bkash_password')) {
                $table->text('bkash_password')->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'bkash_sandbox')) {
                $table->boolean('bkash_sandbox')->default(1);
            }

            if (! Schema::hasColumn('store_settings', 'sslcommerz_enabled')) {
                $table->boolean('sslcommerz_enabled')->default(0);
            }
            if (! Schema::hasColumn('store_settings', 'sslcommerz_store_id')) {
                $table->string('sslcommerz_store_id', 191)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'sslcommerz_store_password')) {
                $table->text('sslcommerz_store_password')->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'sslcommerz_sandbox')) {
                $table->boolean('sslcommerz_sandbox')->default(1);
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'bkash_payment_id')) {
                $table->string('bkash_payment_id', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('online_orders', 'bkash_trx_id')) {
                $table->string('bkash_trx_id', 64)->nullable();
            }
            if (! Schema::hasColumn('online_orders', 'sslcommerz_tran_id')) {
                $table->string('sslcommerz_tran_id', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('online_orders', 'sslcommerz_val_id')) {
                $table->string('sslcommerz_val_id', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            foreach ([
                'sslcommerz_sandbox', 'sslcommerz_store_password', 'sslcommerz_store_id', 'sslcommerz_enabled',
                'bkash_sandbox', 'bkash_password', 'bkash_username', 'bkash_app_secret', 'bkash_app_key', 'bkash_enabled',
            ] as $col) {
                if (Schema::hasColumn('store_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            foreach (['sslcommerz_val_id', 'sslcommerz_tran_id', 'bkash_trx_id', 'bkash_payment_id'] as $col) {
                if (Schema::hasColumn('online_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
