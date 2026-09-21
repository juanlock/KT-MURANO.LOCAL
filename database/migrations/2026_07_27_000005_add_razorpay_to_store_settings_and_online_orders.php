<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razorpay payment gateway for the Online Store (Payment Links flow, same
 * redirect-then-verify pattern as the other store gateways): keys + webhook
 * secret on store_settings (test vs live decided by the rzp_test_/rzp_live_
 * key pair), and the payment link / payment ids on online_orders (the link
 * id is how the callback and webhooks find the pending order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'razorpay_enabled')) {
                $table->boolean('razorpay_enabled')->default(0);
            }
            if (! Schema::hasColumn('store_settings', 'razorpay_key_id')) {
                $table->string('razorpay_key_id', 191)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'razorpay_key_secret')) {
                $table->text('razorpay_key_secret')->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'razorpay_webhook_secret')) {
                $table->string('razorpay_webhook_secret', 191)->nullable();
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'razorpay_payment_link_id')) {
                $table->string('razorpay_payment_link_id', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('online_orders', 'razorpay_payment_id')) {
                $table->string('razorpay_payment_id', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            foreach (['razorpay_webhook_secret', 'razorpay_key_secret', 'razorpay_key_id', 'razorpay_enabled'] as $col) {
                if (Schema::hasColumn('store_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            foreach (['razorpay_payment_id', 'razorpay_payment_link_id'] as $col) {
                if (Schema::hasColumn('online_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
