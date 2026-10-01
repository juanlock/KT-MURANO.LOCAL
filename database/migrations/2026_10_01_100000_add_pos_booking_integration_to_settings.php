<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS Settings -> Company rules -> "Integrate POS with Booking": every POS
 * sale also records a (completed) booking for the same customer, date/time,
 * services and staff, linked to the sale, so it shows in the Bookings list
 * and calendar; the company admins get the booking report by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'pos_booking_integration')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('pos_booking_integration')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'pos_booking_integration')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('pos_booking_integration'));
        }
    }
};
