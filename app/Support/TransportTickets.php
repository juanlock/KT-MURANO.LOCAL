<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Passenger tickets sold on the POS (user's request, 2026-11-03, Turismo Cautivo): a
 * ticket product (code "PAS-{route id}", "PAS-{route id}-VIP" for a VIP seat) is a seat on one
 * scheduled departure and asks, per ticket, the passenger's document, names, luggage and
 * seat. The route of the product is the passenger's stretch: Trujillo -> Chepén can travel on
 * the Lima -> Chepén bus, in a seat emptied at Trujillo (TransportLegs, 2026-11-04).
 * Saved in transport_tickets with the sale, in the sale's transaction.
 */
class TransportTickets
{
    /** Identity documents: type => the number's pattern. */
    public const DOCS = ['dni' => '/^\d{8}$/', 'ce' => '/^[A-Z0-9]{6,12}$/', 'passport' => '/^[A-Z0-9]{5,15}$/', 'ptp' => '/^[A-Z0-9]{6,15}$/'];

    public const PASSENGER_TYPES = ['adult', 'minor', 'infant'];

    /** The document number as stored: digits only for a DNI, uppercase letters / digits otherwise. */
    public static function docNumber(string $type, string $number): string
    {
        return $type === 'dni' ? preg_replace('/\D/', '', $number) : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $number));
    }

    /** product id => [route_id, label, vip, from, to] of the company's ticket products. */
    public static function products(int $companyId): array
    {
        $routes = DB::table('transport_routes as r')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')
            ->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->get(['r.id', 'r.origin_id', 'r.destination_id', 'o.name as o', 'd.name as d'])->keyBy('id');
        $out = [];
        foreach (DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where('code', 'like', 'PAS-%')->get(['id', 'code']) as $p) {
            $rid = (int) substr($p->code, 4);
            if (isset($routes[$rid])) {
                $vip = str_ends_with((string) $p->code, '-VIP');
                $out[(int) $p->id] = ['route_id' => $rid, 'label' => $routes[$rid]->o.' → '.$routes[$rid]->d.($vip ? ' VIP' : ''), 'vip' => $vip,
                    'from' => (int) $routes[$rid]->origin_id, 'to' => (int) $routes[$rid]->destination_id];
            }
        }

        return $out;
    }

    /** Every ticket's VIP twin: 20 % dearer, on sale at the same agencies (Turismo, 2026-11-04). */
    public static function ensureVipProducts(int $companyId): int
    {
        $made = 0;
        foreach (DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where('code', 'like', 'PAS-%')->where('code', 'not like', '%-VIP')->get() as $p) {
            $code = $p->code.'-VIP';
            if (DB::table('products')->where('company_id', $companyId)->where('code', $code)->whereNull('deleted_at')->exists()) {
                continue;
            }
            $row = (array) $p;
            unset($row['id']);
            $price = round((float) $p->price * 1.2, 2);
            $id = DB::table('products')->insertGetId(array_merge($row, ['code' => $code, 'name' => preg_replace('/^(Pasaje|Ticket)/', '$1 VIP', (string) $p->name), 'price' => $price,
                'wholesale_price' => $price, 'min_price' => round($price * 0.8, 2), 'created_at' => now(), 'updated_at' => now()]));
            foreach (DB::table('product_warehouse')->where('product_id', $p->id)->get() as $w) {
                $copy = (array) $w;
                unset($copy['id']);
                DB::table('product_warehouse')->insert(array_merge($copy, ['product_id' => $id]));
            }
            if (DB::table('demo_records')->where('company_id', $companyId)->where('record_type', 'products')->where('record_id', $p->id)->exists()) {
                DB::table('demo_records')->insert(['company_id' => $companyId, 'module' => 'transport', 'record_type' => 'products', 'record_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $made++;
        }

        return $made;
    }

    /** Departures for the stretch of a route (14 days ahead), including the buses that stop there on longer routes. */
    public static function departures(int $companyId, int $routeId): array
    {
        $route = DB::table('transport_routes')->where('id', $routeId)->where('company_id', $companyId)->first(['origin_id', 'destination_id']);
        if (! $route) {
            return [];
        }
        $routes = TransportLegs::routesPassing($companyId, (int) $route->origin_id, (int) $route->destination_id);
        $trips = DB::table('transport_trips as t')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $companyId)->whereIn('t.route_id', $routes)->whereNull('t.deleted_at')
            ->where('t.status', 'scheduled')->whereBetween('t.departure_at', [now()->subDays(2), now()->addDays(14)])
            ->orderBy('t.departure_at')->get(['t.id', 't.route_id', 't.departure_at', 'v.name as vehicle', 'v.seats', 'v.seats_lower', 'v.vip_seats']);
        $out = [];
        foreach ($trips as $t) {
            [$a, $b] = TransportLegs::stretch((int) $t->route_id, (int) $route->origin_id, (int) $route->destination_id);
            $at = \Carbon\Carbon::parse($t->departure_at)->addMinutes(TransportLegs::path((int) $t->route_id)[$a]['minutes']);
            if ($at->isPast()) {
                continue;
            }
            $taken = TransportLegs::takenSeats((int) $t->id, (int) $t->route_id, $a, $b);
            $seats = $t->seats ? (int) $t->seats : null;
            $out[] = ['id' => (int) $t->id, 'departure_at' => $at->format('Y-m-d H:i:s'), 'vehicle' => $t->vehicle, 'sold' => count($taken), 'seats' => $seats,
                'full' => $seats && count($taken) >= $seats];
        }
        usort($out, fn ($x, $y) => strcmp($x['departure_at'], $y['departure_at']));

        return $out;
    }

    /** The seat map of a trip for a stretch: number, floor, VIP, taken. */
    public static function seats(int $companyId, int $tripId, int $routeId): array
    {
        $trip = DB::table('transport_trips')->where('id', $tripId)->where('company_id', $companyId)->whereNull('deleted_at')->first(['route_id', 'vehicle_id']);
        $route = DB::table('transport_routes')->where('id', $routeId)->where('company_id', $companyId)->first(['origin_id', 'destination_id']);
        if (! $trip || ! $route) {
            return [];
        }
        $stretch = TransportLegs::stretch((int) $trip->route_id, (int) $route->origin_id, (int) $route->destination_id);
        if (! $stretch) {
            return [];
        }
        $map = TransportLegs::seatMap(DB::table('vehicles')->where('id', $trip->vehicle_id)->first(['seats', 'seats_lower', 'vip_seats']));
        $taken = TransportLegs::takenSeats($tripId, (int) $trip->route_id, $stretch[0], $stretch[1]);

        return array_values(array_map(fn ($s) => $s + ['taken' => in_array($s['number'], $taken, true)], $map));
    }

    /** The company's Nth-trip rule for a passenger: how many paid trips, and whether this one is free. */
    public static function loyalty(int $companyId, string $docType, string $dni): ?array
    {
        $every = (int) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('transport_loyalty_every');
        if ($every < 2) {
            return null;
        }
        $lastFree = DB::table('transport_tickets')->where('company_id', $companyId)->where('doc_type', $docType)->where('dni', $dni)->where('loyalty_free', 1)->max('id');
        $paid = DB::table('transport_tickets')->where('company_id', $companyId)->where('doc_type', $docType)->where('dni', $dni)->where('loyalty_free', 0)
            ->when($lastFree, fn ($q) => $q->where('id', '>', $lastFree))->count();

        return ['every' => $every, 'paid' => $paid, 'free_now' => $paid >= $every - 1];
    }

    /** The passengers of the sale's ticket lines; a ticket line must bring one per ticket. */
    public static function record(object $sale, array $details): void
    {
        $tickets = self::products((int) $sale->company_id);
        if (! $tickets) {
            return;
        }
        $rows = [];
        $batch = []; // tripId => seats taken in this sale, with their stretch
        foreach ($details as $d) {
            $pid = (int) ($d['product_id'] ?? 0);
            if (! isset($tickets[$pid])) {
                continue;
            }
            $product = $tickets[$pid];
            $passengers = array_values(array_filter((array) ($d['passengers'] ?? []), 'is_array'));
            if (count($passengers) !== (int) round((float) ($d['quantity'] ?? 0))) {
                throw ValidationException::withMessages(['details' => 'Each ticket needs its passenger (DNI, names, luggage).']);
            }
            foreach ($passengers as $p) {
                $docType = isset(self::DOCS[$p['doc_type'] ?? '']) ? $p['doc_type'] : 'dni';
                $dni = self::docNumber($docType, (string) ($p['dni'] ?? ''));
                $first = trim(strip_tags((string) ($p['first_name'] ?? '')));
                $last = trim(strip_tags((string) ($p['last_name'] ?? '')));
                $type = in_array($p['passenger_type'] ?? '', self::PASSENGER_TYPES, true) ? $p['passenger_type'] : 'adult';
                if (! preg_match(self::DOCS[$docType], $dni) || $first === '' || $last === '') {
                    throw ValidationException::withMessages(['details' => 'Passenger: a valid document number (DNI = 8 digits), first names and last names are required.']);
                }
                // The departure: a scheduled trip that goes from there to there and hasn't left that stop.
                $tripId = (int) ($p['trip_id'] ?? 0);
                $trip = $tripId ? DB::table('transport_trips as t')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')->where('t.id', $tripId)->where('t.company_id', $sale->company_id)
                    ->where('t.status', 'scheduled')->whereNull('t.deleted_at')->lockForUpdate()->first(['t.route_id', 't.departure_at', 'v.seats', 'v.seats_lower', 'v.vip_seats']) : null;
                $stretch = $trip ? TransportLegs::stretch((int) $trip->route_id, $product['from'], $product['to']) : null;
                $leaves = $stretch ? \Carbon\Carbon::parse($trip->departure_at)->addMinutes(TransportLegs::path((int) $trip->route_id)[$stretch[0]]['minutes']) : null;
                if (! $stretch || $leaves->isPast()) {
                    throw ValidationException::withMessages(['details' => 'Passenger: choose a scheduled departure of this route.']);
                }
                $map = TransportLegs::seatMap($trip);
                $seat = null;
                if ($map) {
                    $seat = (int) ($p['seat_number'] ?? 0);
                    $taken = TransportLegs::takenSeats($tripId, (int) $trip->route_id, $stretch[0], $stretch[1]);
                    foreach ($batch[$tripId] ?? [] as $b) {
                        if ($b['a'] < $stretch[1] && $b['b'] > $stretch[0]) {
                            $taken[] = $b['seat'];
                        }
                    }
                    if (! $seat) {
                        throw ValidationException::withMessages(['details' => 'Passenger: choose a seat.']);
                    }
                    if ($error = TransportLegs::seatError($map, $taken, $seat, $product['vip'])) {
                        throw ValidationException::withMessages(['details' => $error]);
                    }
                    $batch[$tripId][] = ['a' => $stretch[0], 'b' => $stretch[1], 'seat' => $seat];
                }
                $free = ! empty($p['loyalty_free']) && (self::loyalty((int) $sale->company_id, $docType, $dni)['free_now'] ?? false);
                $rows[] = [
                    'company_id' => $sale->company_id, 'sale_id' => $sale->id, 'product_id' => $pid,
                    'route_id' => $product['route_id'], 'trip_id' => $tripId, 'warehouse_id' => $sale->warehouse_id,
                    'seat_number' => $seat, 'is_vip' => $product['vip'], 'from_location_id' => $product['from'], 'to_location_id' => $product['to'], 'code' => TransportLegs::ticketCode(),
                    'loyalty_free' => $free, 'doc_type' => $docType, 'passenger_type' => $type, 'dni' => $dni, 'first_name' => mb_substr($first, 0, 100), 'last_name' => mb_substr($last, 0, 100),
                    'luggage' => ! empty($p['luggage']), 'created_at' => now(), 'updated_at' => now(),
                    // What this seat was sold for: the line's total (discounts included) per ticket; a loyalty ticket costs nothing.
                    'price' => $free ? 0 : round((float) ($d['subtotal'] ?? ($d['Unit_price'] ?? 0)) / max(1, count($passengers)), 2),
                ];
            }
        }
        if ($rows) {
            DB::table('transport_tickets')->insert($rows);
        }
    }
}
