<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual (offline) storefront payment methods: GCash, Bank Transfer and
 * Cash on Pickup. Each carries the account details the shopper needs plus
 * free-text instructions rendered on checkout / thank-you / order pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            // GCash
            $table->boolean('payment_gcash_enabled')->default(false)->after('payment_mobile_money_enabled');
            $table->string('gcash_account_name', 150)->nullable()->after('payment_gcash_enabled');
            $table->string('gcash_account_number', 60)->nullable()->after('gcash_account_name');
            $table->string('gcash_qr_path', 255)->nullable()->after('gcash_account_number');
            $table->text('gcash_instructions')->nullable()->after('gcash_qr_path');

            // Bank transfer
            $table->boolean('payment_bank_transfer_enabled')->default(false)->after('gcash_instructions');
            $table->string('bank_name', 150)->nullable()->after('payment_bank_transfer_enabled');
            $table->string('bank_account_name', 150)->nullable()->after('bank_name');
            $table->string('bank_account_number', 60)->nullable()->after('bank_account_name');
            $table->string('bank_branch', 150)->nullable()->after('bank_account_number');
            $table->text('bank_instructions')->nullable()->after('bank_branch');

            // Cash on pickup (branch collection)
            $table->boolean('payment_cash_on_pickup_enabled')->default(false)->after('bank_instructions');
            $table->text('pickup_instructions')->nullable()->after('payment_cash_on_pickup_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn([
                'payment_gcash_enabled', 'gcash_account_name', 'gcash_account_number', 'gcash_qr_path', 'gcash_instructions',
                'payment_bank_transfer_enabled', 'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_instructions',
                'payment_cash_on_pickup_enabled', 'pickup_instructions',
            ]);
        });
    }
};
