<?php

namespace App\Services;

use App\Support\TransportLegs;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who registers a parcel doesn't know the departures (user's rule, 2026-11-04): the system puts it on the first bus that goes
 * from there to there and has room in the hold; the customer gets an estimate, like ground shipping, 1 to 3 days.
 * A parcel with no room yet waits: `transport:assign-parcels` tries again, and a cancelled trip's parcels go to the next one.
 */
class TransportParcelAssign
{
    public const ETA_DAYS = [1, 3];

    /** What a passenger's luggage weighs in the hold it shares with the parcels. */
    public const LUGGAGE_KG = 20;

    /** A parcel reaches the bus at least this long before it leaves. */
    private const CUTOFF_MINUTES = 30;

    private const DAYS_AHEAD = 14;

    /** The first bus with room, or null; the parcel's trip is set. Call inside a transaction. */
    public static function assign(object $parcel): ?int
    {
        $from = (int) $parcel->from_location_id;
        $to = (int) $parcel->to_location_id;
        $routes = TransportLegs::routesPassing((int) $parcel->company_id, $from, $to);
        if (! $routes) {
            return null;
        }
        $trips = DB::table('transport_trips')->where('company_id', $parcel->company_id)->whereIn('route_id', $routes)->whereNull('deleted_at')->where('status', 'scheduled')
            ->whereBetween('departure_at', [now()->subDays(2), now()->addDays(self::DAYS_AHEAD)])->orderBy('departure_at')->get(['id', 'route_id', 'departure_at', 'vehicle_id']);
        // By the time it leaves from this agency, a stop on a longer route.
        $leaving = $trips->map(function ($t) use ($from) {
            $stop = collect(TransportLegs::path((int) $t->route_id))->firstWhere('location_id', $from);
            $t->leaves = Carbon::parse($t->departure_at)->addMinutes($stop['minutes'] ?? 0);

            return $t;
        })->filter(fn ($t) => $t->leaves->gt(now()->addMinutes(self::CUTOFF_MINUTES)))->sortBy('leaves');

        foreach ($leaving as $t) {
            DB::table('transport_trips')->where('id', $t->id)->lockForUpdate()->value('id');
            if (self::fits((int) $t->id, (int) $t->vehicle_id, (float) $parcel->volume_m3, (float) $parcel->weight_kg, (int) $parcel->id)) {
                DB::table('transport_parcels')->where('id', $parcel->id)->update(['trip_id' => $t->id, 'updated_at' => now()]);

                return (int) $t->id;
            }
        }

        return null;
    }

    /** Room in the bus's hold for this parcel, the luggage of its passengers counted. */
    public static function fits(int $tripId, int $vehicleId, float $volume, float $weight, ?int $except = null): bool
    {
        $limit = DB::table('vehicles')->where('id', $vehicleId)->first(['cargo_volume_m3', 'cargo_weight_kg']);
        if (! $limit) {
            return false;
        }
        $used = DB::table('transport_parcels')->where('trip_id', $tripId)->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->selectRaw('COALESCE(SUM(volume_m3), 0) v, COALESCE(SUM(weight_kg), 0) w')->first();
        $luggage = DB::table('transport_tickets')->where('trip_id', $tripId)->where('luggage', 1)->count() * self::LUGGAGE_KG;
        if ($limit->cargo_volume_m3 !== null && (float) $used->v + $volume > (float) $limit->cargo_volume_m3) {
            return false;
        }

        return $limit->cargo_weight_kg === null || (float) $used->w + $luggage + $weight <= (float) $limit->cargo_weight_kg;
    }

    /** Parcels waiting for a bus, oldest first. Returns how many got one. */
    public static function assignPending(?int $companyId = null): int
    {
        $made = 0;
        foreach (DB::table('transport_parcels')->whereNull('trip_id')->whereNull('deleted_at')->where('status', 'received')->whereNotNull('from_location_id')->whereNotNull('to_location_id')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->orderBy('id')->limit(500)->get() as $parcel) {
            $made += DB::transaction(fn () => self::assign($parcel) ? 1 : 0);
        }

        return $made;
    }

    /** A cancelled trip's parcels still at the agency look for another bus. */
    public static function releaseTrip(int $tripId): int
    {
        $company = DB::table('transport_trips')->where('id', $tripId)->value('company_id');
        DB::table('transport_parcels')->where('trip_id', $tripId)->whereNull('deleted_at')->where('status', 'received')->update(['trip_id' => null, 'updated_at' => now()]);

        return self::assignPending($company ? (int) $company : null);
    }

    /** The estimate given to the customer, like ground shipping. */
    public static function eta(?string $receivedOn = null): array
    {
        $day = Carbon::parse($receivedOn ?: now()->toDateString());

        return [$day->copy()->addDays(self::ETA_DAYS[0])->toDateString(), $day->copy()->addDays(self::ETA_DAYS[1])->toDateString()];
    }

    /** No bus can take this box at all: nothing would ever carry it. */
    public static function neverFits(int $companyId, float $volume, float $weight): bool
    {
        $buses = DB::table('vehicles')->where('company_id', $companyId)->whereNull('deleted_at')->where('type', 'bus')->get(['cargo_volume_m3', 'cargo_weight_kg']);
        if ($buses->isEmpty()) {
            return false;
        }

        return ! $buses->contains(fn ($b) => ($b->cargo_volume_m3 === null || $volume <= (float) $b->cargo_volume_m3) && ($b->cargo_weight_kg === null || $weight <= (float) $b->cargo_weight_kg));
    }
}
