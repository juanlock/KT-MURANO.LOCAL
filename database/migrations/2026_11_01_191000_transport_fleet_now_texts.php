<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Transport board, "Fleet now" box (user's request, 2026-11-01): current trips and
 * where each bus is (at an agency, in transit, in maintenance). Texts only. Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Transport_Fleet_Now' => ['Fleet now', 'Flota ahora'],
        'Transport_At_Agency' => ['At an agency', 'En agencia'],
        'Transport_In_Transit' => ['In transit', 'En tránsito'],
        'Transport_In_Maintenance' => ['In maintenance', 'En mantenimiento'],
        'Transport_Current_Trips' => ['Current trips', 'Viajes actuales'],
        'Transport_No_Trips_Now' => ['No trip on the road right now', 'Ningún viaje en ruta ahora'],
        'Transport_Buses_Here' => ['Buses here', 'Buses aquí'],
        'Transport_Arrives' => ['arrives', 'llega'],
        'Transport_Next_Departure' => ['next departure', 'próxima salida'],
        'Transport_No_Location' => ['Location unknown', 'Sin ubicación'],
        'Transport_Pilots' => ['Pilots', 'Pilotos'],
        'Transport_Hostesses' => ['Hostesses', 'Azafatas'],
        'Transport_Map_Hint' => ['The number on each agency = buses parked there now; the moving dots = buses on the road.', 'El número en cada agencia = buses estacionados ahí ahora; los puntos sobre las rutas = buses en camino.'],
    ];

    public function up(): void
    {
        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
