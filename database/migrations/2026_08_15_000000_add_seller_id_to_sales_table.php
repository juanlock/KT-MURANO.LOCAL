<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change Salesperson During Checkout: split attribution from ownership.
 * `user_id` stays the authenticated cashier who rang the sale up (permissions,
 * audit); `seller_id` is the employee picked on the POS screen who gets credit
 * on receipts and seller reports. NULL means "seller is the cashier", so
 * legacy rows need no backfill — readers use COALESCE(seller_id, user_id).
 */
class AddSellerIdToSalesTable extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('seller_id')->nullable()->after('user_id')->index();
        });
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('seller_id');
        });
    }
}
