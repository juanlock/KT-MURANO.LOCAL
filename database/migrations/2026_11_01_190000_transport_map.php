<?php

use App\Support\PlaceCoordinates;
use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Transport board on the dashboard (user's request, 2026-11-01): locations get a
 * position (lat / lng, filled from App\Support\PlaceCoordinates when empty) and the
 * terminal an agency belongs to (hub_id: same city, else the nearest terminal).
 * Turismo Cautivo: its agencies' terminals and its routes' km / hours (only empty
 * values). The board's texts. Safe to run twice.
 */
return new class extends Migration
{
    // Turismo Cautivo: agency -> terminal (the ones the nearest-terminal rule can't place).
    private const TURISMO_HUBS = [
        'LIMA' => ['OFICINA GALVES', 'ILO'],
        'CHICLAYO' => ['JOSE GALVEZ', 'LA PAMPA'],
    ];

    // Turismo Cautivo's routes by road: [terminal, terminal, km, minutes].
    private const TURISMO_ROUTES = [
        ['LIMA', 'CHICLAYO', 770, 720], ['LIMA', 'CHEPEN', 650, 630], ['LIMA', 'TRUJILLO', 560, 540],
        ['CHICLAYO', 'CHEPEN', 105, 105], ['CHICLAYO', 'TRUJILLO', 210, 210], ['CHEPEN', 'TRUJILLO', 125, 120],
    ];

    private const TEXTS = [
        'Transport_Board' => ['Transport board', 'Tablero de transporte'],
        'Transport_Map' => ['Routes map', 'Mapa de rutas'],
        'Transport_Terminals' => ['Terminals', 'Terminales'],
        'Transport_Agencies' => ['Agencies', 'Locales'],
        'Transport_Online' => ['Online channels', 'Canales online'],
        'Transport_Fleet' => ['Fleet', 'Flota'],
        'Transport_Staff_Count' => ['Staff', 'Personal'],
        'Transport_Trips_Period' => ['Trips', 'Viajes'],
        'Transport_Km' => ['Km traveled', 'Km recorridos'],
        'Transport_Departures' => ['Departures', 'Salidas'],
        'Transport_Crew' => ['Crew', 'Tripulantes'],
        'Transport_Buses' => ['Buses', 'Buses'],
        'Transport_On_Road' => ['On the road now', 'En ruta ahora'],
        'Transport_Pilot_Hours' => ['Pilot hours', 'Horas de los pilotos'],
        'Transport_Pilot_Record' => ['Most hours in the period', 'Récord de horas del período'],
        'Transport_Hours_Danger' => ['Danger: over the safe limit, check his rest days', 'Peligro: supera el límite seguro, revisar sus descansos'],
        'Transport_Hours_Warning' => ['Close to the safe limit', 'Cerca del límite seguro'],
        'Transport_Hours_Limit' => ['Safe limit', 'Límite seguro'],
        'Transport_Estimated' => ['estimated', 'estimado'],
        'Transport_No_Position' => ['without a position on the map', 'sin ubicación en el mapa'],
        'Transport_Latitude' => ['Latitude', 'Latitud'],
        'Transport_Longitude' => ['Longitude', 'Longitud'],
        'Transport_Hub' => ['Terminal it belongs to', 'Terminal a la que pertenece'],
    ];

    public function up(): void
    {
        Schema::table('transport_locations', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_locations', 'lat')) {
                $t->decimal('lat', 9, 6)->nullable()->after('description');
            }
            if (! Schema::hasColumn('transport_locations', 'lng')) {
                $t->decimal('lng', 9, 6)->nullable()->after('lat');
            }
            if (! Schema::hasColumn('transport_locations', 'hub_id')) {
                $t->unsignedInteger('hub_id')->nullable()->after('lng'); // the terminal an agency belongs to
            }
        });

        // Positions from the city / name.
        foreach (DB::table('transport_locations')->whereNull('deleted_at')->whereNull('lat')->get(['id', 'name', 'city']) as $l) {
            if ($p = PlaceCoordinates::find($l->name, $l->city)) {
                DB::table('transport_locations')->where('id', $l->id)->update(['lat' => $p[0], 'lng' => $p[1]]);
            }
        }

        $turismo = DB::table('companies')->where('name', 'Turismo Cautivo')->whereNull('deleted_at')->value('id');
        if ($turismo) {
            $term = DB::table('transport_locations')->where('company_id', $turismo)->whereNull('deleted_at')->where('kind', 'terminal')->pluck('id', 'name');
            foreach (self::TURISMO_HUBS as $hub => $names) {
                if (isset($term[$hub])) {
                    DB::table('transport_locations')->where('company_id', $turismo)->whereNull('hub_id')->whereIn('name', $names)->update(['hub_id' => $term[$hub]]);
                }
            }
            foreach (self::TURISMO_ROUTES as [$a, $b, $km, $min]) {
                if (isset($term[$a], $term[$b])) {
                    DB::table('transport_routes')->where('company_id', $turismo)->whereNull('deleted_at')
                        ->where(fn ($q) => $q->where(fn ($x) => $x->where('origin_id', $term[$a])->where('destination_id', $term[$b]))
                            ->orWhere(fn ($x) => $x->where('origin_id', $term[$b])->where('destination_id', $term[$a])))
                        ->whereNull('distance_km')->update(['distance_km' => $km, 'duration_min' => $min]);
                }
            }
        }

        // Every other agency: the terminal of its city, else the nearest one.
        foreach (DB::table('transport_locations')->whereNull('deleted_at')->where('kind', 'agency')->whereNull('hub_id')->get() as $a) {
            $terminals = DB::table('transport_locations')->where('company_id', $a->company_id)->whereNull('deleted_at')->where('kind', 'terminal')->get(['id', 'city', 'lat', 'lng']);
            $hub = $a->city ? $terminals->first(fn ($t) => $t->city && mb_strtolower($t->city) === mb_strtolower($a->city)) : null;
            if (! $hub && $a->lat !== null) {
                $hub = $terminals->filter(fn ($t) => $t->lat !== null)
                    ->sortBy(fn ($t) => PlaceCoordinates::km((float) $a->lat, (float) $a->lng, (float) $t->lat, (float) $t->lng))->first();
            }
            if ($hub) {
                DB::table('transport_locations')->where('id', $a->id)->update(['hub_id' => $hub->id]);
            }
        }

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
