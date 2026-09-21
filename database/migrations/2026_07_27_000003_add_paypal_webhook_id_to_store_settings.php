<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PayPal webhook verification (verify-webhook-signature) requires the
 * Webhook ID assigned by PayPal when the webhook is registered on
 * developer.paypal.com — same setting the SaaS gateway keeps. Paystack
 * needs no extra column: its webhooks are HMAC-signed with the secret key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'paypal_webhook_id')) {
                $table->string('paypal_webhook_id', 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (Schema::hasColumn('store_settings', 'paypal_webhook_id')) {
                $table->dropColumn('paypal_webhook_id');
            }
        });
    }
};
