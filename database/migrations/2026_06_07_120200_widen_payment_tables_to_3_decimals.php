<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen payment money columns (montant, change) from decimal(15,2) to decimal(15,3)
 * so payment amounts entered with 3 decimals (e.g. 0.066) are stored exactly when
 * "Enable 3 Decimal Pricing" is on, matching the sales/products columns. Scale 3
 * holds existing 2-decimal values losslessly, so this is safe regardless of the
 * setting and needs no data backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sales', function (Blueprint $table) {
            $table->decimal('montant', 15, 3)->change();
            $table->decimal('change', 15, 3)->default(0)->change();
        });

        Schema::table('payment_sale_returns', function (Blueprint $table) {
            $table->decimal('montant', 15, 3)->change();
            $table->decimal('change', 15, 3)->default(0)->change();
        });

        Schema::table('payment_purchases', function (Blueprint $table) {
            $table->decimal('montant', 15, 3)->change();
            $table->decimal('change', 15, 3)->default(0)->change();
        });

        Schema::table('payment_purchase_returns', function (Blueprint $table) {
            $table->decimal('montant', 15, 3)->change();
            $table->decimal('change', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_sales', function (Blueprint $table) {
            $table->decimal('montant', 15, 2)->change();
            $table->decimal('change', 15, 2)->default(0)->change();
        });

        Schema::table('payment_sale_returns', function (Blueprint $table) {
            $table->decimal('montant', 15, 2)->change();
            $table->decimal('change', 15, 2)->default(0)->change();
        });

        Schema::table('payment_purchases', function (Blueprint $table) {
            $table->decimal('montant', 15, 2)->change();
            $table->decimal('change', 15, 2)->default(0)->change();
        });

        Schema::table('payment_purchase_returns', function (Blueprint $table) {
            $table->decimal('montant', 15, 2)->change();
            $table->decimal('change', 15, 2)->default(0)->change();
        });
    }
};
