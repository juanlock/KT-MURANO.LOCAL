<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AuraSoft's departures become Murano departures (2026-11-23, user: "procede ... adecua los tableros"): every CURRENT
 * AuraSoft row (the latest figures of a departure, AuraSoftImport) makes or updates one transport_trips row -- route by the
 * places' names, its bus by plate, the departure time, and its figures as "reported" (passengers, tickets S/, parcels,
 * parcels S/, excess S/), source = aurasoft. So Reporte de salidas, Rentabilidad, Resumen and Mando operativo read them
 * like any departure. A trip's own fuel / tolls are never touched here (they come from the fuel and toll reports).
 * Removing a file puts the earlier figures back; a departure no file holds any more is removed when it came from AuraSoft.
 */
class AuraSoftTrips
{
    /** AuraSoft's / the client's short names of the places. */
    private const ALIASES = ['CIX' => 'CHICLAYO', 'TRUX' => 'TRUJILLO', 'TRU' => 'TRUJILLO', 'CHE' => 'CHEPEN', 'CHEPÉN' => 'CHEPEN', 'LIM' => 'LIMA'];

    /** Sync every departure of the company (or only these departure keys). Returns counts. */
    public static function sync(int $companyId, ?array $keys = null): array
    {
        $places = DB::table('transport_locations')->where('company_id', $companyId)->whereNull('deleted_at')
            ->get(['id', 'name', 'kind'])->mapWithKeys(fn ($l) => [self::norm($l->name) => $l]);
        $routes = DB::table('transport_routes')->where('company_id', $companyId)->whereNull('deleted_at')
            ->get(['id', 'origin_id', 'destination_id', 'duration_min'])->keyBy(fn ($r) => $r->origin_id.'>'.$r->destination_id);
        $out = ['created' => 0, 'updated' => 0, 'removed' => 0, 'no_route' => [], 'no_bus' => []];

        $q = DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('is_current', 1);
        if ($keys !== null) {
            $q->whereIn('departure_key', $keys ?: ['-']);
        }
        $held = [];
        foreach ($q->orderBy('trip_date')->orderBy('departure_time')->get() as $r) {
            $held[$r->departure_key] = true;
            $o = $places[self::norm($r->origin)] ?? null;
            $d = $places[self::norm($r->destination)] ?? null;
            $route = ($o && $d) ? ($routes[$o->id.'>'.$d->id] ?? null) : null;
            if (! $route) {
                $out['no_route'][$r->route] = true;
                continue;
            }
            if (! $r->vehicle_id) {
                $out['no_bus'][$r->plate] = true;
            }
            $dep = Carbon::parse($r->trip_date.' '.$r->departure_time);
            $tripId = $r->trip_id
                ?: DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('departure_key', $r->departure_key)->whereNotNull('trip_id')->orderByDesc('id')->value('trip_id');
            if ($tripId && ! DB::table('transport_trips')->where('id', $tripId)->whereNull('deleted_at')->exists()) {
                $tripId = null;
            }
            // A departure already in Murano (typed in Salidas del día, the client's report): same bus, within 90 minutes.
            if (! $tripId && $r->vehicle_id) {
                $tripId = DB::table('transport_trips')->where('company_id', $companyId)->whereNull('deleted_at')->where('vehicle_id', $r->vehicle_id)
                    ->where('route_id', $route->id)->whereBetween('departure_at', [$dep->copy()->subMinutes(90), $dep->copy()->addMinutes(90)])->value('id');
            }
            $set = [
                'reported_passengers' => (int) $r->seats, 'reported_income' => round((float) $r->ticket_income, 2),
                'reported_parcels' => (int) $r->parcels, 'reported_parcel_income' => round((float) $r->parcel_income, 2),
                'reported_excess_income' => round((float) $r->excess_income, 2), 'source' => 'aurasoft', 'updated_at' => now(),
            ];
            if ($tripId) {
                DB::table('transport_trips')->where('id', $tripId)->update($set);
                $out['updated']++;
            } else {
                $tripId = DB::table('transport_trips')->insertGetId($set + [
                    'company_id' => $companyId, 'route_id' => $route->id, 'vehicle_id' => $r->vehicle_id, 'departure_at' => $dep,
                    'arrival_at' => $route->duration_min ? $dep->copy()->addMinutes((int) $route->duration_min) : null,
                    'status' => $dep->isPast() ? 'completed' : 'scheduled', 'notes' => 'AuraSoft', 'created_at' => now(),
                ]);
                $out['created']++;
            }
            DB::table('transport_aurasoft_rows')->where('id', $r->id)->update(['trip_id' => $tripId]);
            $touched[$tripId] = true;
        }
        // AuraSoft sometimes lists one departure twice (an empty row at the planned time and the real one a few minutes
        // off): both land on the same trip, whose figures are the sum of its current rows.
        foreach (array_keys($touched ?? []) as $id) {
            $s = DB::table('transport_aurasoft_rows')->where('trip_id', $id)->where('is_current', 1)
                ->selectRaw('COUNT(*) n, SUM(seats) seats, SUM(ticket_income) ti, SUM(parcels) p, SUM(parcel_income) pi, SUM(excess_income) ei')->first();
            if ((int) $s->n > 1) {
                DB::table('transport_trips')->where('id', $id)->update(['reported_passengers' => (int) $s->seats, 'reported_income' => round((float) $s->ti, 2),
                    'reported_parcels' => (int) $s->p, 'reported_parcel_income' => round((float) $s->pi, 2), 'reported_excess_income' => round((float) $s->ei, 2)]);
            }
        }

        // Keys no file holds any more: their AuraSoft-made departures go (never one with tickets or parcels of its own).
        if ($keys !== null) {
            foreach (array_diff($keys, array_keys($held)) as $key) {
                $ids = DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('departure_key', $key)->whereNotNull('trip_id')->pluck('trip_id')->unique();
                foreach ($ids as $id) {
                    $own = DB::table('transport_tickets')->where('trip_id', $id)->exists() || DB::table('transport_parcels')->where('trip_id', $id)->exists();
                    if (! $own && DB::table('transport_trips')->where('id', $id)->where('source', 'aurasoft')->where('notes', 'AuraSoft')->delete()) {
                        $out['removed']++;
                    }
                }
            }
        }
        $out['no_route'] = array_keys($out['no_route']);
        $out['no_bus'] = array_keys($out['no_bus']);

        return $out;
    }

    private static function norm(?string $name): string
    {
        $n = mb_strtoupper(trim(\Illuminate\Support\Str::ascii((string) $name)));

        return self::ALIASES[$n] ?? $n;
    }
}
