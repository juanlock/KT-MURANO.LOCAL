<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Passenger tickets sold on the POS (user's request, 2026-11-03, Turismo Cautivo): a
 * ticket product (code "PAS-{route id}" of a route of the company) is a seat on one
 * scheduled departure of that route (transport_trips) and asks, per ticket, the
 * passenger's DNI, first names, last names and whether they travel with luggage.
 * Saved in transport_tickets with the sale, in the sale's transaction.
 */
class TransportTickets
{
    /** product id => [route_id, label] of the company's ticket products. */
    public static function products(int $companyId): array
    {
        $routes = DB::table('transport_routes as r')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')
            ->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->get(['r.id', 'o.name as o', 'd.name as d'])->keyBy('id');
        $out = [];
        foreach (DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where('code', 'like', 'PAS-%')->get(['id', 'code']) as $p) {
            $rid = (int) substr($p->code, 4);
            if (isset($routes[$rid])) {
                $out[(int) $p->id] = ['route_id' => $rid, 'label' => $routes[$rid]->o.' → '.$routes[$rid]->d];
            }
        }

        return $out;
    }

    /** The route's scheduled departures from now on (14 days): [id, departure_at, vehicle, sold]. */
    public static function departures(int $companyId, int $routeId): array
    {
        $trips = DB::table('transport_trips as t')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $companyId)->where('t.route_id', $routeId)->whereNull('t.deleted_at')
            ->where('t.status', 'scheduled')->whereBetween('t.departure_at', [now(), now()->addDays(14)])
            ->orderBy('t.departure_at')->get(['t.id', 't.departure_at', 'v.name as vehicle']);
        $sold = DB::table('transport_tickets')->whereIn('trip_id', $trips->pluck('id'))->selectRaw('trip_id, count(*) n')->groupBy('trip_id')->pluck('n', 'trip_id');

        return $trips->map(fn ($t) => ['id' => (int) $t->id, 'departure_at' => $t->departure_at, 'vehicle' => $t->vehicle, 'sold' => (int) ($sold[$t->id] ?? 0)])->all();
    }

    /** The passengers of the sale's ticket lines; a ticket line must bring one per ticket. */
    public static function record(object $sale, array $details): void
    {
        $tickets = self::products((int) $sale->company_id);
        if (! $tickets) {
            return;
        }
        $rows = [];
        foreach ($details as $d) {
            $pid = (int) ($d['product_id'] ?? 0);
            if (! isset($tickets[$pid])) {
                continue;
            }
            $passengers = array_values(array_filter((array) ($d['passengers'] ?? []), 'is_array'));
            if (count($passengers) !== (int) round((float) ($d['quantity'] ?? 0))) {
                throw ValidationException::withMessages(['details' => 'Each ticket needs its passenger (DNI, names, luggage).']);
            }
            foreach ($passengers as $p) {
                $dni = preg_replace('/\D/', '', (string) ($p['dni'] ?? ''));
                $first = trim(strip_tags((string) ($p['first_name'] ?? '')));
                $last = trim(strip_tags((string) ($p['last_name'] ?? '')));
                if (strlen($dni) !== 8 || $first === '' || $last === '') {
                    throw ValidationException::withMessages(['details' => 'Passenger: DNI of 8 digits, first names and last names are required.']);
                }
                // The departure: a scheduled trip of this ticket's route, not gone yet.
                $tripId = (int) ($p['trip_id'] ?? 0);
                $tripOk = $tripId && DB::table('transport_trips')->where('id', $tripId)->where('company_id', $sale->company_id)
                    ->where('route_id', $tickets[$pid]['route_id'])->where('status', 'scheduled')->whereNull('deleted_at')
                    ->where('departure_at', '>', now())->exists();
                if (! $tripOk) {
                    throw ValidationException::withMessages(['details' => 'Passenger: choose a scheduled departure of this route.']);
                }
                $rows[] = [
                    'company_id' => $sale->company_id, 'sale_id' => $sale->id, 'product_id' => $pid,
                    'route_id' => $tickets[$pid]['route_id'], 'trip_id' => $tripId, 'warehouse_id' => $sale->warehouse_id,
                    'dni' => $dni, 'first_name' => mb_substr($first, 0, 100), 'last_name' => mb_substr($last, 0, 100),
                    'luggage' => ! empty($p['luggage']), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        if ($rows) {
            DB::table('transport_tickets')->insert($rows);
        }
    }
}
