<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS Settings -> "Confirm each sale with the cashier's PIN"
 * (settings.pos_sale_pin_required): at payment the person making the sale
 * types their PIN and the sale is recorded under that user, while the POS
 * session stays with the user who opened the register.
 * sales.cash_register_id: the register a POS sale was rung up on, so the
 * register's close-out includes every user's sales made on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'pos_sale_pin_required')) {
                $table->boolean('pos_sale_pin_required')->default(false);
            }
        });
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'cash_register_id')) {
                $table->unsignedBigInteger('cash_register_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('pos_sale_pin_required');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['cash_register_id']);
            $table->dropColumn('cash_register_id');
        });
    }
};
