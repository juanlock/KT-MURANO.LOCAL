<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the additional Booking Orders fields (tray, sales person, product
 * details, delivery information and amount). Idempotent: each column is only
 * added if it does not already exist, so it is safe to run on existing DBs.
 */
class AddBookingExtraFields extends Migration
{
    public function up()
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'tray_id')) {
                $table->integer('tray_id')->nullable()->after('product_id')->index('bookings_tray_id');
            }
            if (! Schema::hasColumn('bookings', 'sales_person_id')) {
                $table->integer('sales_person_id')->nullable()->after('tray_id')->index('bookings_sales_person_id');
            }
            if (! Schema::hasColumn('bookings', 'product_name')) {
                $table->string('product_name', 255)->nullable()->after('sales_person_id');
            }
            if (! Schema::hasColumn('bookings', 'product_description')) {
                $table->text('product_description')->nullable()->after('product_name');
            }
            if (! Schema::hasColumn('bookings', 'delivery_date')) {
                $table->date('delivery_date')->nullable()->after('booking_end_time');
            }
            if (! Schema::hasColumn('bookings', 'delivery_time')) {
                $table->time('delivery_time')->nullable()->after('delivery_date');
            }
            if (! Schema::hasColumn('bookings', 'delivery_address')) {
                $table->text('delivery_address')->nullable()->after('delivery_time');
            }
            if (! Schema::hasColumn('bookings', 'amount')) {
                $table->decimal('amount', 10, 2)->nullable()->after('delivery_address');
            }
        });
    }

    public function down()
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach ([
                'tray_id',
                'sales_person_id',
                'product_name',
                'product_description',
                'delivery_date',
                'delivery_time',
                'delivery_address',
                'amount',
            ] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
