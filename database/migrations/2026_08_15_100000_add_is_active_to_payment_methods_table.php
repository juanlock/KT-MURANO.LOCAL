<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enable/disable toggle for payment methods (Settings → Payment Methods).
 * Inactive methods are hidden from every "record a payment" picker (POS,
 * sales/purchases/returns/expenses payment modals) but stay visible in
 * reports and on historical payments. Defaults to active so existing
 * installs are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_methods', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (Schema::hasColumn('payment_methods', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
