<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order Tax was always a percentage of the invoice subtotal (no toggle),
 * unlike Discount which already supports percent ('1') or fixed ('2') via
 * discount_Method. Adds the same toggle for tax -- default '1' (percent)
 * so every existing sale's stored tax_rate keeps meaning exactly what it
 * always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'tax_method')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('tax_method', 1)->default('1')->after('tax_rate');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('tax_method');
        });
    }
};
