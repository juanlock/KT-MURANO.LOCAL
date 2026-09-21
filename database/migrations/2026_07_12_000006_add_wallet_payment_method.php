<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add a "Wallet" tender so POS sales can be paid from the customer's wallet.
     * Idempotent, and never overwrites an existing row: the Wallet row simply
     * gets the next auto-increment id. Code resolves the wallet tender by name
     * (WalletService::paymentMethodId), not by a hardcoded id.
     */
    public function up(): void
    {
        $exists = DB::table('payment_methods')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['wallet'])
            ->exists();

        if (! $exists) {
            DB::table('payment_methods')->insert(['name' => 'Wallet']);
        }
    }

    public function down(): void
    {
        DB::table('payment_methods')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['wallet'])
            ->delete();
    }
};
