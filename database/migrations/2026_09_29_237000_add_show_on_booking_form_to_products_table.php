<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product form -> Status -> "Show on booking form": whether a service is offered
 * on the public "Make an appointment" form (/book). Default ON for every product
 * (existing and new), so the form keeps listing what it listed before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'show_on_booking_form')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('show_on_booking_form')->default(true);
            });
        }
        // Except the generic "Appointment" service booking invoices use for
        // bookings without a service (App\Services\BookingSaleService).
        DB::table('products')->where('code', 'BOOKING-SVC')->update(['show_on_booking_form' => 0]);

        if (! DB::table('translations')->where('key', 'Show_On_Booking_Form')->exists()) {
            DB::table('translations')->insert([
                'key' => 'Show_On_Booking_Form', 'is_default' => 1,
                'en' => 'Show on booking form', 'es' => 'Mostrar en el formulario de reservas',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            forget_db_translations();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'show_on_booking_form')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('show_on_booking_form');
            });
        }
        DB::table('translations')->where('key', 'Show_On_Booking_Form')->delete();
        forget_db_translations();
    }
};
