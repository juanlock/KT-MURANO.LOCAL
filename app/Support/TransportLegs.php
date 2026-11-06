<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A trip with stops, like a flight with stopovers (user's rule, 2026-11-04): the Lima -> Chepén bus reaches
 * Trujillo at 9:00 and leaves again at 9:25, selling the seats that were emptied there. So a seat
 * is taken per stretch: two tickets can share it when their stretches don't overlap.
 * A route's path = origin, its transport_route_stops in order, destination.
 */
class TransportLegs
{
    private static array $paths = [];

    /** [['location_id', 'km', 'minutes'], ...] of a route, origin first. */
    public static function path(int $routeId): array
    {
        if (isset(self::$paths[$routeId])) {
            return self::$paths[$routeId];
        }
        $route = DB::table('transport_routes')->where('id', $routeId)->first(['origin_id', 'destination_id', 'distance_km', 'duration_min']);
        if (! $route) {
            return [];
        }
        $path = [['location_id' => (int) $route->origin_id, 'km' => 0.0, 'minutes' => 0]];
        foreach (DB::table('transport_route_stops')->where('route_id', $routeId)->orderBy('seq')->get(['location_id', 'km', 'minutes', 'dwell_min']) as $s) {
            // Leaves the stop after its dwell: that's the departure a passenger boarding there gets.
            $path[] = ['location_id' => (int) $s->location_id, 'km' => (float) $s->km, 'minutes' => (int) $s->minutes + (int) $s->dwell_min];
        }
        $path[] = ['location_id' => (int) $route->destination_id, 'km' => (float) $route->distance_km, 'minutes' => (int) $route->duration_min];

        return self::$paths[$routeId] = $path;
    }

    public static function forget(): void
    {
        self::$paths = [];
    }

    /** The stretch [from index, to index] on the route, or null when it doesn't go from there to there. */
    public static function stretch(int $routeId, int $fromLocation, int $toLocation): ?array
    {
        $ids = array_column(self::path($routeId), 'location_id');
        $a = array_search($fromLocation, $ids, true);
        $b = array_search($toLocation, $ids, true);

        return $a !== false && $b !== false && $a < $b ? [$a, $b] : null;
    }

    /** Routes of the company that carry passengers from there to there. */
    public static function routesPassing(int $companyId, int $fromLocation, int $toLocation): array
    {
        $out = [];
        foreach (DB::table('transport_routes')->where('company_id', $companyId)->whereNull('deleted_at')->pluck('id') as $id) {
            if (self::stretch((int) $id, $fromLocation, $toLocation)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /** The bus's seats: first floor numbered first, the first N are VIP. Empty = no seats on record. */
    public static function seatMap(?object $vehicle): array
    {
        $total = (int) ($vehicle->seats ?? 0);
        if ($total < 1) {
            return [];
        }
        $lower = (int) ($vehicle->seats_lower ?? 0);
        $vip = (int) ($vehicle->vip_seats ?? 0);
        $out = [];
        for ($n = 1; $n <= $total; $n++) {
            $out[$n] = ['number' => $n, 'floor' => $n <= $lower ? 1 : 2, 'vip' => $n <= $vip];
        }

        return $out;
    }

    /** Seats taken on the trip anywhere along the stretch [a, b) of its route. */
    public static function takenSeats(int $tripId, int $routeId, int $a, int $b): array
    {
        $taken = [];
        foreach (DB::table('transport_tickets')->where('trip_id', $tripId)->whereNotNull('seat_number')->get(['seat_number', 'from_location_id', 'to_location_id']) as $t) {
            [$ta, $tb] = self::ticketStretch($routeId, $t);
            if ($ta < $b && $tb > $a) {
                $taken[(int) $t->seat_number] = true;
            }
        }

        return array_keys($taken);
    }

    /** A ticket's stretch on the trip's route; an old ticket without one holds the whole route. */
    public static function ticketStretch(int $routeId, object $ticket): array
    {
        $path = self::path($routeId);
        $found = ($ticket->from_location_id && $ticket->to_location_id) ? self::stretch($routeId, (int) $ticket->from_location_id, (int) $ticket->to_location_id) : null;

        return $found ?: [0, max(1, count($path) - 1)];
    }

    /** A short code for the boarding pass. */
    public static function ticketCode(): string
    {
        do {
            $code = 'B'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 9));
        } while (DB::table('transport_tickets')->where('code', $code)->exists());

        return $code;
    }

    /**
     * The ticket's seat is valid and free (called with the trip locked). $batch = seats already
     * taken in the same sale on this stretch.
     *  - a VIP ticket sits in a VIP seat;
     *  - a regular ticket goes to a VIP seat only when no regular seat is left: the last-minute
     *    passenger who gets one without paying more, as on a plane.
     */
    public static function seatError(array $map, array $taken, int $seat, bool $vipTicket): ?string
    {
        if (! isset($map[$seat])) {
            return "Seat $seat does not exist on this bus.";
        }
        if (in_array($seat, $taken, true)) {
            return "Seat $seat is already taken on this stretch.";
        }
        if ($vipTicket && ! $map[$seat]['vip']) {
            return 'A VIP ticket needs a VIP seat.';
        }
        if (! $vipTicket && $map[$seat]['vip']) {
            foreach ($map as $n => $s) {
                if (! $s['vip'] && ! in_array($n, $taken, true)) {
                    return 'There are regular seats free: this seat is VIP.';
                }
            }
        }

        return null;
    }
}
