<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen transfer money columns from decimal(15,2) to decimal(15,3) so 3-decimal
 * costs/totals are stored exactly when "Enable 3 Decimal Pricing" is on, matching
 * the sales/purchases columns. Scale 3 holds existing 2-decimal values losslessly.
 * The `items` (count) and `tax_rate` (percentage) columns are left unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->decimal('TaxNet', 15, 3)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 3)->nullable()->default(0)->change();
            $table->decimal('shipping', 15, 3)->nullable()->default(0)->change();
            $table->decimal('GrandTotal', 15, 3)->default(0)->change();
        });

        Schema::table('transfer_details', function (Blueprint $table) {
            $table->decimal('cost', 15, 3)->change();
            $table->decimal('TaxNet', 15, 3)->nullable()->change();
            $table->decimal('discount', 15, 3)->nullable()->change();
            $table->decimal('total', 15, 3)->change();
        });

        Schema::table('transfer_money', function (Blueprint $table) {
            $table->decimal('amount', 15, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->decimal('TaxNet', 15, 2)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 2)->nullable()->default(0)->change();
            $table->decimal('shipping', 15, 2)->nullable()->default(0)->change();
            $table->decimal('GrandTotal', 15, 2)->default(0)->change();
        });

        Schema::table('transfer_details', function (Blueprint $table) {
            $table->decimal('cost', 15, 2)->change();
            $table->decimal('TaxNet', 15, 2)->nullable()->change();
            $table->decimal('discount', 15, 2)->nullable()->change();
            $table->decimal('total', 15, 2)->change();
        });

        Schema::table('transfer_money', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });
    }
};
