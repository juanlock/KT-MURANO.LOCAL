<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The dashboard's "Departures of the day" tab (Turismo Cautivo, 2026-11-05): the same report the client keeps by hand, per
 * agency and day: one row per bus with its boarding points and times, passengers, free seats, amount, toll and fuel.
 * A trip with reported figures (reported_passengers / reported_income) shows them; any other counts its tickets.
 */
class TransportDepartures
{
    /** An amount under this shows in red. Taken from the client's reports (S/ 1,900 red, S/ 2,470 not): to confirm. */
    public const LOW_AMOUNT = 2000;

    /** @param array<int>|null $warehouses the user's locations (null = all) */
    public static function compute(int $companyId, string $date, ?int $agencyId, ?array $warehouses): array
    {
        $agencies = DB::table('transport_locations')->where('company_id', $companyId)->whereNull('deleted_at')->where('kind', 'agency')->where('active', 1)
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses))->orderBy('name')->get(['id', 'name']);
        $ids = $agencies->pluck('id')->all();
        $date = self::reportDay($companyId, $date, $ids);

        $trips = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as d', 'd.id', '=', 'r.destination_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $companyId)->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')->whereIn('r.origin_id', $ids)->whereDate('t.departure_at', $date)
            ->orderBy('t.departure_at')->get(['t.id', 't.departure_at', 't.status', 't.fuel_cost', 't.reported_passengers', 't.reported_income', 'r.origin_id', 'd.name as destination', 'v.name as bus', 'v.seats']);

        $counts = $trips->countBy('origin_id');
        $agency = $agencyId && in_array($agencyId, $ids, true) ? $agencyId : ($counts->sortDesc()->keys()->first() ?? ($ids[0] ?? null));
        $rows = $trips->where('origin_id', $agency)->values();
        $tripIds = $rows->pluck('id')->all();

        $tickets = DB::table('transport_tickets')->whereIn('trip_id', $tripIds)->selectRaw('trip_id, COUNT(*) n, SUM(price) income, SUM(loyalty_free) free_trips')->groupBy('trip_id')->get()->keyBy('trip_id');
        $tolls = DB::table('transport_tolls')->whereIn('trip_id', $tripIds)->whereNull('deleted_at')->selectRaw('trip_id, SUM(amount) paid')->groupBy('trip_id')->pluck('paid', 'trip_id');
        $boardings = DB::table('transport_trip_boardings as b')->join('transport_locations as l', 'l.id', '=', 'b.location_id')->whereIn('b.trip_id', $tripIds)
            ->orderBy('b.seq')->get(['b.trip_id', 'l.name', 'b.label', 'b.board_at'])->groupBy('trip_id');
        $origin = $agencies->firstWhere('id', $agency);
        $price = $agency ? DB::table('transport_day_prices')->where('location_id', $agency)->whereDate('day', $date)->first() : null;

        $out = $rows->map(function ($t) use ($tickets, $tolls, $boardings, $origin) {
            $x = $tickets[$t->id] ?? null;
            $sold = $t->reported_passengers !== null ? (int) $t->reported_passengers : (int) ($x->n ?? 0);
            $paid = $tolls[$t->id] ?? null;

            return [
                'id' => (int) $t->id, 'bus' => (string) $t->bus, 'seats' => $t->seats ? (int) $t->seats : null, 'destination' => $t->destination, 'status' => $t->status,
                'points' => isset($boardings[$t->id])
                    ? $boardings[$t->id]->map(fn ($b) => ['name' => $b->label ?: $b->name, 'full' => $b->name, 'at' => $b->board_at])->values()->all()
                    : [['name' => $origin->name ?? '', 'full' => $origin->name ?? '', 'at' => $t->departure_at]],
                'passengers' => $sold,
                'free_trips' => (int) ($x->free_trips ?? 0),
                'free' => $t->seats ? max(0, (int) $t->seats - $sold) : null,
                'amount' => round($t->reported_income !== null ? (float) $t->reported_income : (float) ($x->income ?? 0), 2),
                // What was recorded, as in the client's report; the route's expected toll only counts in Profit by route.
                'toll' => $paid !== null ? round((float) $paid, 2) : null,
                'fuel' => $t->fuel_cost !== null ? round((float) $t->fuel_cost, 2) : null,
            ];
        })->all();

        return [
            'enabled' => true, 'date' => $date, 'agency_id' => $agency, 'low_amount' => self::LOW_AMOUNT,
            'agencies' => $agencies->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'departures' => (int) ($counts[$a->id] ?? 0)])->values()->all(),
            'price' => $price ? ['high' => (float) $price->price_high, 'low' => (float) $price->price_low] : null,
            'rows' => $out,
        ];
    }

    /**
     * The day to show: the one asked for, unless it holds only demo trips or none, and the client has reported an earlier
     * day. Then the latest report, so the tab never opens on made-up departures while a real one exists.
     *
     * @param  array<int>  $agencyIds
     */
    private static function reportDay(int $companyId, string $date, array $agencyIds): string
    {
        $trips = fn () => DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->where('t.company_id', $companyId)->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')->whereIn('r.origin_id', $agencyIds);

        $real = $trips()->whereDate('t.departure_at', $date)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('demo_records as d')
            ->where('d.company_id', $companyId)->where('d.record_type', 'transport_trips')->whereColumn('d.record_id', 't.id'))->exists();
        if ($real) {
            return $date;
        }
        $latest = $trips()->whereNotNull('t.reported_income')->whereDate('t.departure_at', '<=', $date)->max('t.departure_at');

        return $latest ? substr((string) $latest, 0, 10) : $date;
    }
}
