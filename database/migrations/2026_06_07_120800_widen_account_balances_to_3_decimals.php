<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the accounts balance columns from decimal(15,2) to decimal(15,3) so
 * 3-decimal balances are stored exactly when "Enable 3 Decimal Pricing" is on.
 * Scale 3 holds existing 2-decimal values losslessly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('initial_balance', 15, 3)->change();
            $table->decimal('balance', 15, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('initial_balance', 15, 2)->change();
            $table->decimal('balance', 15, 2)->change();
        });
    }
};
