<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle monitoring (user's request, 2026-11-06). A trip's control points = its boarding points (the client's terminals,
 * transport_trip_boardings) or else the route's origin, then the route's stops and its destination. The plan is worked out
 * from the trip's current times; what happened is stored in transport_trip_checkpoints with the delay of that moment.
 *
 * Times: now() is the app's timezone (America/New_York), the trips are typed in Peru's time. They match from November
 * to March; during New York's summer time a delay would read 60 minutes more (no per-company timezone yet).
 */
class TransportControl
{
    /** Below this a bus counts as stopped. */
    public const STOP_KMH = 5.0;

    /** Inside this distance of a terminal's coordinates a stopped bus has arrived. Many coordinates are approximate. */
    public const RADIUS_M = 600;

    /** Late from here on (our figure, the client hasn't set one). */
    public const LATE_MIN = 10;

    public const REPORT_KINDS = ['traffic', 'breakdown', 'accident', 'road', 'passenger', 'other'];

    private static array $routes = [];

    /** Metres between two points. */
    public static function metres(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $toRad = M_PI / 180;
        $dLat = ($bLat - $aLat) * $toRad;
        $dLng = ($bLng - $aLng) * $toRad;
        $h = sin($dLat / 2) ** 2 + cos($aLat * $toRad) * cos($bLat * $toRad) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    /** The route's stops and destination, once per request. */
    private static function route(int $routeId): array
    {
        if (isset(self::$routes[$routeId])) {
            return self::$routes[$routeId];
        }
        $route = DB::table('transport_routes')->where('id', $routeId)->first(['origin_id', 'destination_id', 'duration_min']);
        $stops = DB::table('transport_route_stops')->where('route_id', $routeId)->orderBy('seq')->get(['location_id', 'minutes', 'dwell_min']);

        return self::$routes[$routeId] = ['route' => $route, 'stops' => $stops];
    }

    /**
     * The trip's control points in order, with the plan and what happened:
     * [location_id, name, label, lat, lng, plan_arrive, plan_depart, first, last, checkpoint...].
     * $boardings and $checkpoints can come ready (the monitor's list), to spare one query per trip.
     */
    public static function points(object $trip, ?iterable $boardings = null, ?iterable $checkpoints = null): array
    {
        $r = self::route((int) $trip->route_id);
        if (! $r['route']) {
            return [];
        }
        $dep = Carbon::parse($trip->departure_at);
        $boardings ??= DB::table('transport_trip_boardings')->where('trip_id', $trip->id)->orderBy('seq')->get(['location_id', 'label', 'board_at']);
        $plan = [];
        $add = function (int $locationId, ?Carbon $arrive, ?Carbon $depart, ?string $label = null) use (&$plan) {
            if (! isset($plan[$locationId])) {
                $plan[$locationId] = ['location_id' => $locationId, 'label' => $label, 'plan_arrive' => $arrive, 'plan_depart' => $depart];
            }
        };
        $i = 0;
        foreach ($boardings as $b) {
            if (! $b->location_id) {
                continue;
            }
            $at = $b->board_at ? Carbon::parse($b->board_at) : null;
            $add((int) $b->location_id, $i === 0 ? null : $at, $at ?: ($i === 0 ? $dep->copy() : null), $b->label);
            $i++;
        }
        if (! $plan) {
            $add((int) $r['route']->origin_id, null, $dep->copy());
        }
        foreach ($r['stops'] as $s) {
            $arrive = $dep->copy()->addMinutes((int) $s->minutes);
            $add((int) $s->location_id, $arrive, $arrive->copy()->addMinutes((int) $s->dwell_min));
        }
        $add((int) $r['route']->destination_id, $trip->arrival_at ? Carbon::parse($trip->arrival_at) : $dep->copy()->addMinutes((int) $r['route']->duration_min), null);

        $checkpoints ??= DB::table('transport_trip_checkpoints')->where('trip_id', $trip->id)->get();
        $done = collect($checkpoints)->keyBy('location_id');
        $places = DB::table('transport_locations')->whereIn('id', array_keys($plan))->get(['id', 'name', 'lat', 'lng'])->keyBy('id');
        $list = array_values($plan);
        $last = count($list) - 1;
        foreach ($list as $n => &$p) {
            $place = $places[$p['location_id']] ?? null;
            $c = $done[$p['location_id']] ?? null;
            $p += [
                'name' => $place->name ?? '', 'lat' => $place && $place->lat !== null ? (float) $place->lat : null, 'lng' => $place && $place->lng !== null ? (float) $place->lng : null,
                'first' => $n === 0, 'last' => $n === $last,
                'checkpoint_id' => $c ? (int) $c->id : null, 'arrived_at' => $c->arrived_at ?? null, 'departed_at' => $c->departed_at ?? null, 'skipped' => (bool) ($c->skipped_at ?? false),
                'arrive_source' => $c->arrive_source ?? null, 'depart_source' => $c->depart_source ?? null, 'arrive_off_m' => $c && $c->arrive_off_m !== null ? (int) $c->arrive_off_m : null,
                'arrive_delay_min' => $c && $c->arrive_delay_min !== null ? (int) $c->arrive_delay_min : null, 'depart_delay_min' => $c && $c->depart_delay_min !== null ? (int) $c->depart_delay_min : null,
                'has_photo' => (bool) ($c->photo ?? false), 'note' => $c->note ?? null,
            ];
        }
        unset($p);

        return $list;
    }

    /** What comes next: [index, action arrive|depart] or null when the trip is finished. */
    public static function next(array $points): ?array
    {
        $touched = -1;
        foreach ($points as $i => $p) {
            if ($p['arrived_at'] || $p['departed_at'] || $p['skipped']) {
                $touched = $i;
            }
        }
        if ($touched === -1) {
            return $points ? ['index' => 0, 'action' => 'depart'] : null;
        }
        $p = $points[$touched];
        if ($p['arrived_at'] && ! $p['departed_at'] && ! $p['last']) {
            return ['index' => $touched, 'action' => 'depart'];
        }

        return $touched < count($points) - 1 ? ['index' => $touched + 1, 'action' => 'arrive'] : null;
    }

    /** May a position of this trip be recorded now? From half an hour before leaving, up to six hours past the plan. */
    public static function tracking(object $trip, array $points): bool
    {
        if ($trip->status === 'in_progress') {
            return true;
        }
        if ($trip->status !== 'scheduled' || ! $points) {
            return false;
        }
        $start = $points[0]['plan_depart'] ?? Carbon::parse($trip->departure_at);
        $end = end($points)['plan_arrive'] ?? Carbon::parse($trip->departure_at)->addDay();

        return now()->between($start->copy()->subMinutes(30), $end->copy()->addHours(6));
    }

    /** The crew role of this user on the trip: pilot, hostess, or null when they aren't on it. */
    public static function crewRole(int $tripId, int $userId): ?string
    {
        return DB::table('transport_trip_crew as c')->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->where('c.trip_id', $tripId)->where('e.user_id', $userId)->whereNull('e.deleted_at')->value('c.role');
    }

    /**
     * Why a pilot can't report right now (null = they can). The phone hides the screen by itself; this is the check it can't
     * skip: a position is needed, its speed, and the distance from the bus's position of 15 s to 3 min ago.
     */
    public static function drivingBlock(object $trip, ?string $role, array $fix): ?string
    {
        // A finished trip sends no positions: only the photo of its last arrival is left to add.
        if ($role !== 'pilot' || $trip->status === 'completed') {
            return null;
        }
        if (! isset($fix['lat'], $fix['lng'])) {
            return 'Turn on the location to report.';
        }
        $moving = 'Stop the vehicle to report.';
        if (isset($fix['speed']) && (float) $fix['speed'] > self::STOP_KMH) {
            return $moving;
        }
        $prior = DB::table('transport_vehicle_positions')->where('trip_id', $trip->id)
            ->whereBetween('recorded_at', [now()->subSeconds(180), now()->subSeconds(15)])->orderByDesc('recorded_at')->first();
        if (! $prior) {
            return 'Wait a few seconds: checking that the vehicle is stopped.';
        }
        $seconds = max(1, abs(now()->diffInSeconds(Carbon::parse($prior->recorded_at), false)));
        // GPS wanders while standing still; up to 100 m of it is forgiven.
        $tolerance = min(100, (float) ($fix['accuracy'] ?? 30) + (float) ($prior->accuracy_m ?? 30));
        $moved = max(0, self::metres((float) $prior->lat, (float) $prior->lng, (float) $fix['lat'], (float) $fix['lng']) - $tolerance);

        return ($moved / $seconds) * 3.6 > self::STOP_KMH ? $moving : null;
    }

    /** Stores a position: at most one per bus every 25 s, whichever crew phone sends it. */
    public static function storePosition(object $trip, int $userId, array $fix): bool
    {
        $recent = DB::table('transport_vehicle_positions')->where('trip_id', $trip->id)->where('recorded_at', '>', now()->subSeconds(25))->exists();
        if ($recent) {
            return false;
        }
        DB::table('transport_vehicle_positions')->insert([
            'company_id' => $trip->company_id, 'trip_id' => $trip->id, 'vehicle_id' => $trip->vehicle_id, 'user_id' => $userId,
            'lat' => $fix['lat'], 'lng' => $fix['lng'], 'speed_kmh' => $fix['speed'] ?? null, 'accuracy_m' => isset($fix['accuracy']) ? (int) round($fix['accuracy']) : null,
            'source' => 'phone', 'recorded_at' => now(),
        ]);

        return true;
    }

    /**
     * Marks the next control point, one transaction with the trip locked. $source manual | gps. The first departure puts
     * the trip on the road and the last arrival completes it; the planned times stay as they are.
     * Returns an error text, or null when done.
     */
    public static function mark(int $tripId, int $locationId, string $action, string $source, ?int $userId, array $fix = [], ?string $photo = null, ?string $note = null): ?string
    {
        return DB::transaction(function () use ($tripId, $locationId, $action, $source, $userId, $fix, $photo, $note) {
            $trip = DB::table('transport_trips')->where('id', $tripId)->lockForUpdate()->first();
            if (! $trip || ! in_array($trip->status, ['scheduled', 'in_progress'], true)) {
                return 'This trip is not running.';
            }
            $points = self::points($trip);
            $next = self::next($points);
            if (! $next || $points[$next['index']]['location_id'] !== $locationId || $next['action'] !== $action) {
                return 'That is not the next control point: refresh the page.';
            }
            $p = $points[$next['index']];
            $now = now();
            $plan = $action === 'arrive' ? $p['plan_arrive'] : $p['plan_depart'];
            $delay = $plan ? (int) round($plan->diffInMinutes($now, false)) : null;
            $set = ['updated_at' => $now];
            if ($action === 'arrive') {
                $off = isset($fix['lat'], $fix['lng']) && $p['lat'] !== null ? (int) round(self::metres((float) $fix['lat'], (float) $fix['lng'], $p['lat'], $p['lng'])) : null;
                $set += ['arrived_at' => $now, 'arrived_by' => $userId, 'arrive_source' => $source, 'arrive_lat' => $fix['lat'] ?? null, 'arrive_lng' => $fix['lng'] ?? null,
                    'arrive_off_m' => $off, 'arrive_delay_min' => $delay];
                if ($photo) {
                    $set += ['photo' => $photo, 'photo_at' => $now];
                }
                if ($note) {
                    $set['note'] = $note;
                }
            } else {
                $set += ['departed_at' => $now, 'departed_by' => $userId, 'depart_source' => $source, 'depart_delay_min' => $delay];
            }
            self::saveCheckpoint($trip, $locationId, $set);
            if ($action === 'arrive' && $p['last']) {
                DB::table('transport_trips')->where('id', $tripId)->update(['status' => 'completed', 'updated_at' => $now]);
            } elseif ($trip->status === 'scheduled') {
                DB::table('transport_trips')->where('id', $tripId)->update(['status' => 'in_progress', 'updated_at' => $now]);
            }

            return null;
        });
    }

    /** The bus did not call at the next point. */
    public static function skip(int $tripId, int $locationId, int $userId): ?string
    {
        return DB::transaction(function () use ($tripId, $locationId, $userId) {
            $trip = DB::table('transport_trips')->where('id', $tripId)->lockForUpdate()->first();
            if (! $trip || ! in_array($trip->status, ['scheduled', 'in_progress'], true)) {
                return 'This trip is not running.';
            }
            $points = self::points($trip);
            $next = self::next($points);
            if (! $next || $points[$next['index']]['location_id'] !== $locationId || $next['action'] !== 'arrive' || $points[$next['index']]['last']) {
                return 'That control point can not be skipped: refresh the page.';
            }
            self::saveCheckpoint($trip, $locationId, ['skipped_at' => now(), 'skipped_by' => $userId, 'updated_at' => now()]);

            return null;
        });
    }

    private static function saveCheckpoint(object $trip, int $locationId, array $set): void
    {
        $id = DB::table('transport_trip_checkpoints')->where('trip_id', $trip->id)->where('location_id', $locationId)->value('id');
        if ($id) {
            DB::table('transport_trip_checkpoints')->where('id', $id)->update($set);
        } else {
            DB::table('transport_trip_checkpoints')->insert($set + ['company_id' => $trip->company_id, 'trip_id' => $trip->id, 'location_id' => $locationId, 'created_at' => now()]);
        }
    }

    /**
     * What a new position says by itself: stopped inside the next terminal's radius (twice in a row) = arrived, without a photo
     * yet; moving out of the radius of the terminal it was at = left. Terminals without coordinates are left to the buttons.
     * Returns 'arrive', 'depart' or null.
     */
    public static function autoDetect(object $trip, array $fix): ?string
    {
        $points = self::points($trip);
        $next = self::next($points);
        if (! $next) {
            return null;
        }
        $p = $points[$next['index']];
        if ($p['lat'] === null) {
            return null;
        }
        $distance = self::metres((float) $fix['lat'], (float) $fix['lng'], $p['lat'], $p['lng']);
        $speed = (float) ($fix['speed'] ?? 0);
        if ($next['action'] === 'depart') {
            // The first terminal, not before its time: on its way there a bus is also far from it.
            $onTime = ! $p['first'] || ($p['plan_depart'] && now()->gte($p['plan_depart']->copy()->subMinutes(5)));
            if ($distance > self::RADIUS_M && $speed > self::STOP_KMH * 2 && $onTime) {
                return self::mark((int) $trip->id, $p['location_id'], 'depart', 'gps', null, $fix) === null ? 'depart' : null;
            }

            return null;
        }
        if ($distance > self::RADIUS_M || $speed > self::STOP_KMH) {
            return null;
        }
        $prior = DB::table('transport_vehicle_positions')->where('trip_id', $trip->id)->where('recorded_at', '>', now()->subSeconds(240))
            ->where('recorded_at', '<', now()->subSeconds(20))->orderByDesc('recorded_at')->first();
        if (! $prior || self::metres((float) $prior->lat, (float) $prior->lng, $p['lat'], $p['lng']) > self::RADIUS_M) {
            return null;
        }

        return self::mark((int) $trip->id, $p['location_id'], 'arrive', 'gps', null, $fix) === null ? 'arrive' : null;
    }
}
