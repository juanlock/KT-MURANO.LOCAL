<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Defaults -> Booking (per company): opening hours of the
 * public "Make an appointment" form's day / time-slot picker.
 *  - booking_open_time / booking_close_time: first slot start / closing time
 *    (the last slot must END by closing);
 *  - booking_slot_minutes: slot length (15, 30, 45, 60);
 *  - booking_closed_days: comma-separated weekdays closed (0 = Sunday .. 6).
 * Defaults: every day, 09:00-19:00, 30-minute slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'booking_open_time')) {
                $table->string('booking_open_time', 5)->default('09:00');
            }
            if (! Schema::hasColumn('settings', 'booking_close_time')) {
                $table->string('booking_close_time', 5)->default('19:00');
            }
            if (! Schema::hasColumn('settings', 'booking_slot_minutes')) {
                $table->unsignedSmallInteger('booking_slot_minutes')->default(30);
            }
            if (! Schema::hasColumn('settings', 'booking_closed_days')) {
                $table->string('booking_closed_days', 20)->default('');
            }
        });

        $keys = [
            'Booking_Hours' => ['Online booking hours', 'Horario de reservas en línea'],
            'Booking_Hours_Help' => [
                'Opening hours for the public "Make an appointment" form: visitors pick a day, then one of the free time slots. The last slot ends by closing time.',
                'Horario para el formulario público "Hacer una cita": el visitante elige un día y luego uno de los horarios disponibles. El último horario termina a la hora de cierre.',
            ],
            'Opens_At' => ['Opens at', 'Abre a las'],
            'Closes_At' => ['Closes at', 'Cierra a las'],
            'Slot_Length' => ['Slot length', 'Duración del horario'],
            'Closed_Days' => ['Closed days', 'Días cerrados'],
            'Minutes_Short' => ['min', 'min'],
        ];
        foreach ($keys as $key => [$en, $es]) {
            if (! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert([
                    'key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        forget_db_translations();
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['booking_open_time', 'booking_close_time', 'booking_slot_minutes', 'booking_closed_days'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        DB::table('translations')->whereIn('key', [
            'Booking_Hours', 'Booking_Hours_Help', 'Opens_At', 'Closes_At', 'Slot_Length', 'Closed_Days', 'Minutes_Short',
        ])->delete();
        forget_db_translations();
    }
};
