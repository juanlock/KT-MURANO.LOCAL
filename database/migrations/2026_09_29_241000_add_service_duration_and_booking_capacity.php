<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service durations for booking, and slot blocking.
 *
 * - products.service_duration (minutes, multiples of 30; null = not set, the
 *   booking form then assumes 60): Product form -> Details, service products.
 *   A booking occupies [start, start + duration); the public form only offers
 *   start times whose whole duration is free (App\Http\Controllers\PublicBookingController).
 * - settings.booking_capacity: appointments allowed at the same time (per
 *   company, default 1 = a booked time is blocked for everyone else).
 *
 * Initial durations (company 3's salon services -- the only services with
 * durations today, written as "Duration: N min" in their notes):
 *   Barber group -> 30 min; Hair Salon group -> 60 min (as asked);
 *   every other service with a "Duration: N min" note -> N rounded UP to the
 *   next 30 min (15 -> 30, 45 -> 60, 75 -> 90). Services without one stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'service_duration')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedSmallInteger('service_duration')->nullable();
            });
        }
        if (! Schema::hasColumn('settings', 'booking_capacity')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('booking_capacity')->default(1);
            });
        }

        $services = DB::table('products as p')
            ->leftJoin('product_groups as g', 'g.id', '=', 'p.product_group_id')
            ->where('p.type', 'is_service')->whereNull('p.service_duration')
            ->get(['p.id', 'p.note', 'g.name as grp']);
        foreach ($services as $s) {
            $grp = strtolower(trim((string) $s->grp));
            if ($grp === 'barber') {
                $min = 30;
            } elseif ($grp === 'hair salon') {
                $min = 60;
            } elseif (preg_match('/Duration:\s*(\d+)\s*min/i', (string) $s->note, $m) && (int) $m[1] > 0) {
                $min = (int) (ceil(((int) $m[1]) / 30) * 30);
            } else {
                continue;
            }
            DB::table('products')->where('id', $s->id)->update(['service_duration' => min($min, 600)]);
        }

        $keys = [
            'Service_Duration' => ['Service duration', 'Duración del servicio'],
            'Service_Duration_Help' => [
                'How long the appointment takes. The online booking form reserves this much time and blocks it for other bookings.',
                'Cuánto dura la cita. El formulario de reservas en línea reserva este tiempo y lo bloquea para otras reservas.',
            ],
            'Not_Set' => ['Not set', 'Sin definir'],
            'Booking_Capacity' => ['Appointments at the same time', 'Citas al mismo tiempo'],
            'Booking_Capacity_Help' => [
                'How many bookings may overlap. 1 = once a time is booked, it is blocked for everyone else; raise it if several staff work at once.',
                'Cuántas reservas pueden coincidir. 1 = una vez reservado un horario, queda bloqueado para los demás; auméntelo si trabajan varias personas a la vez.',
            ],
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
        if (Schema::hasColumn('products', 'service_duration')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('service_duration'));
        }
        if (Schema::hasColumn('settings', 'booking_capacity')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('booking_capacity'));
        }
        DB::table('translations')->whereIn('key', [
            'Service_Duration', 'Service_Duration_Help', 'Not_Set', 'Booking_Capacity', 'Booking_Capacity_Help',
        ])->delete();
        forget_db_translations();
    }
};
