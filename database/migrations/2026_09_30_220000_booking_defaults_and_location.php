<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookings:
 *  - bookings.warehouse_id: the location the appointment (and its invoice)
 *    belongs to.
 *  - settings (System Settings -> Defaults -> Booking): what a NEW booking
 *    starts with -- location, customer (Walk-in), station (tray) and whether
 *    the sales person defaults to the signed-in user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'warehouse_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unsignedInteger('warehouse_id')->nullable()->after('tray_id');
            });
        }

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'booking_default_warehouse_id')) {
                $table->unsignedInteger('booking_default_warehouse_id')->nullable();
            }
            if (! Schema::hasColumn('settings', 'booking_default_client_id')) {
                $table->unsignedInteger('booking_default_client_id')->nullable();
            }
            if (! Schema::hasColumn('settings', 'booking_default_tray_id')) {
                $table->unsignedInteger('booking_default_tray_id')->nullable();
            }
            if (! Schema::hasColumn('settings', 'booking_default_sales_person_self')) {
                $table->boolean('booking_default_sales_person_self')->default(true);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'warehouse_id')) {
            Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('warehouse_id'));
        }
        Schema::table('settings', function (Blueprint $table) {
            foreach (['booking_default_warehouse_id', 'booking_default_client_id', 'booking_default_tray_id', 'booking_default_sales_person_self'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
