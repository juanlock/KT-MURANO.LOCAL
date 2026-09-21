<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the expenses/deposits `amount` columns from decimal(15,2) to decimal(15,3)
 * so 3-decimal amounts are stored exactly when "Enable 3 Decimal Pricing" is on.
 * Scale 3 holds existing 2-decimal values losslessly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('amount', 15, 3)->change();
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->decimal('amount', 15, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });
    }
};
