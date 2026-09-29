<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Defaults -> Booking (per company): show/hide optional fields
 * on the Booking Create/Edit form. All default ON (= today's form).
 * Status off: the field is hidden, new bookings are saved as Pending and an edit
 * keeps the booking's current status (it can still change from the Booking List).
 */
return new class extends Migration
{
    private array $cols = [
        'booking_delivery_date_enabled', 'booking_delivery_time_enabled',
        'booking_status_enabled', 'booking_delivery_address_enabled',
    ];

    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ($this->cols as $c) {
                if (! Schema::hasColumn('settings', $c)) {
                    $table->boolean($c)->default(true);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ($this->cols as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
