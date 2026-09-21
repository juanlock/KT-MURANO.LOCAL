<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paystack payment gateway for the Online Store (same flow as the Stocky
 * SaaS PaystackGateway): keys on store_settings (test vs live is decided by
 * the sk_test_/sk_live_ key pair, so no sandbox flag), and the reference /
 * transaction id on online_orders (the reference is how the verify callback
 * finds the pending order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'paystack_enabled')) {
                $table->boolean('paystack_enabled')->default(0);
            }
            if (! Schema::hasColumn('store_settings', 'paystack_public_key')) {
                $table->string('paystack_public_key', 191)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'paystack_secret_key')) {
                $table->text('paystack_secret_key')->nullable();
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'paystack_reference')) {
                $table->string('paystack_reference', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('online_orders', 'paystack_transaction_id')) {
                $table->string('paystack_transaction_id', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            foreach (['paystack_secret_key', 'paystack_public_key', 'paystack_enabled'] as $col) {
                if (Schema::hasColumn('store_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            foreach (['paystack_transaction_id', 'paystack_reference'] as $col) {
                if (Schema::hasColumn('online_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
