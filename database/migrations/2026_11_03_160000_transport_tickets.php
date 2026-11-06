<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Passenger tickets (user's request, 2026-11-03): a ticket sold on the POS is for one
 * scheduled departure (trip) and keeps its passenger -- DNI, first names, last names,
 * luggage yes / no (App\Support\TransportTickets).
 * Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Transport_Passenger' => ['Passenger', 'Datos del pasajero'],
        'Transport_DNI' => ['DNI', 'DNI'],
        'Transport_First_Names' => ['First names', 'Nombres'],
        'Transport_Last_Names' => ['Last names', 'Apellidos'],
        'Transport_Luggage' => ['Luggage', 'Equipaje'],
        'Transport_With_Luggage' => ['With luggage', 'Con equipaje'],
        'Transport_No_Luggage' => ['No luggage', 'Sin equipaje'],
        'Transport_Departure' => ['Departure', 'Salida'],
        'Transport_No_Departures' => ['No scheduled departure for this route', 'No hay salidas programadas para esta ruta'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transport_tickets')) {
            Schema::create('transport_tickets', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('sale_id')->index();
                $t->unsignedInteger('product_id');
                $t->unsignedInteger('route_id')->nullable();
                $t->unsignedInteger('trip_id')->nullable()->index(); // the scheduled departure
                $t->unsignedInteger('warehouse_id')->nullable();
                $t->string('dni', 12)->index();
                $t->string('first_name', 100);
                $t->string('last_name', 100);
                $t->boolean('luggage')->default(false);
                $t->timestamps();
            });
        }
        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                ->where(fn ($q) => $q->whereNull('en')->orWhere('en', $key))->update(['en' => $en]);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
