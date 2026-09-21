<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe used to be "enabled" only in the sense that keys were stored, so the
 * single way to stop offering cards was to delete the credentials. This flag
 * lets the admin switch card payments off at the storefront and keep the keys.
 * Defaults to on, so existing installs with keys behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->boolean('payment_stripe_enabled')->default(true)->after('payment_mobile_money_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn('payment_stripe_enabled');
        });
    }
};
