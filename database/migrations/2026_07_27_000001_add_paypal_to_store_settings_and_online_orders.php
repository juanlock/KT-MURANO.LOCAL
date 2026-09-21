<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PayPal payment gateway for the Online Store (same flow as the Stocky SaaS
 * PaypalGateway): credentials + sandbox switch on store_settings, and the
 * PayPal order/capture ids on online_orders (the paypal_order_id is how the
 * return/cancel redirects find the pending order to capture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'paypal_enabled')) {
                $table->boolean('paypal_enabled')->default(0);
            }
            if (! Schema::hasColumn('store_settings', 'paypal_client_id')) {
                $table->string('paypal_client_id', 191)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'paypal_client_secret')) {
                $table->text('paypal_client_secret')->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'paypal_test_mode')) {
                $table->boolean('paypal_test_mode')->default(1);
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'paypal_order_id')) {
                $table->string('paypal_order_id', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('online_orders', 'paypal_capture_id')) {
                $table->string('paypal_capture_id', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            foreach (['paypal_test_mode', 'paypal_client_secret', 'paypal_client_id', 'paypal_enabled'] as $col) {
                if (Schema::hasColumn('store_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            foreach (['paypal_capture_id', 'paypal_order_id'] as $col) {
                if (Schema::hasColumn('online_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
