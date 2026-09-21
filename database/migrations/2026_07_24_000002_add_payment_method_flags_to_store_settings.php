<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            // On/off for the storefront checkout methods that have no other flag.
            // (Credit card is gated by the Stripe keys; wallet by wallet_enabled.)
            $table->boolean('payment_cod_enabled')->default(true)->after('wallet_min_withdrawal');
            $table->boolean('payment_mobile_money_enabled')->default(true)->after('payment_cod_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn(['payment_cod_enabled', 'payment_mobile_money_enabled']);
        });
    }
};
