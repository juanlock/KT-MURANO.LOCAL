<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen quotation money columns from decimal(15,2) to decimal(15,3) so 3-decimal
 * prices/totals are stored exactly when "Enable 3 Decimal Pricing" is on, matching
 * the sales/purchases columns. Scale 3 holds existing 2-decimal values losslessly.
 * The tax_rate (percentage) column is left unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('TaxNet', 15, 3)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 3)->nullable()->default(0)->change();
            $table->decimal('shipping', 15, 3)->nullable()->default(0)->change();
            $table->decimal('GrandTotal', 15, 3)->change();
        });

        Schema::table('quotation_details', function (Blueprint $table) {
            $table->decimal('price', 15, 3)->change();
            $table->decimal('TaxNet', 15, 3)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 3)->nullable()->default(0)->change();
            $table->decimal('total', 15, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('TaxNet', 15, 2)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 2)->nullable()->default(0)->change();
            $table->decimal('shipping', 15, 2)->nullable()->default(0)->change();
            $table->decimal('GrandTotal', 15, 2)->change();
        });

        Schema::table('quotation_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('TaxNet', 15, 2)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 2)->nullable()->default(0)->change();
            $table->decimal('total', 15, 2)->change();
        });
    }
};
