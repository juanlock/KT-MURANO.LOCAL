<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            // Master switch for the E-Wallet feature (storefront + POS).
            $table->boolean('wallet_enabled')->default(false)->after('return_window_days');
            // Allow the balance to go below zero (credit / instalment scenarios).
            $table->boolean('wallet_allow_negative')->default(false)->after('wallet_enabled');
            // Where refunds land when a return is approved: wallet | original.
            $table->string('wallet_refund_destination', 20)->default('wallet')->after('wallet_allow_negative');
            // Withdrawals let customers cash out their balance (requires admin approval).
            $table->boolean('wallet_withdrawal_enabled')->default(false)->after('wallet_refund_destination');
            $table->decimal('wallet_min_withdrawal', 15, 2)->default(10)->after('wallet_withdrawal_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn([
                'wallet_enabled',
                'wallet_allow_negative',
                'wallet_refund_destination',
                'wallet_withdrawal_enabled',
                'wallet_min_withdrawal',
            ]);
        });
    }
};
