<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Mayte's two reports as boards (Turismo Cautivo, 2026-11-23: "adecua los tableros para que se muestren como producción por
 * bus y reporte del día de ventas"). Both read Murano's departures -- AuraSoft's figures, the client's daily report, the
 * POS tickets -- so they hold whatever reached Murano, not a copy of the Excel.
 *
 *  - byBus(): "Producción por bus" -- per bus, its departures from Lima and from Chiclayo (passengers, tickets, parcels,
 *    excess), the income, the fuel and tolls recorded and what is left (income - fuel - tolls, like the client's sheet).
 *    busTrips(): one bus's round trips, as on the client's per-bus sheet.
 *  - daySales(): "Reporte del día de ventas" -- a day, Lima's and Chiclayo's departures side by side: boarding points and
 *    times, passengers, free seats, amount, toll, fuel, parcels and excess, and the seats by seller (AuraSoft).
 */
class TransportProduction
{
    /** The line's two ends (the boards' two halves). */
    private static function ends(int $cid): array
    {
        $ids = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')
            ->whereIn(DB::raw('UPPER(name)'), ['LIMA', 'CHICLAYO'])->pluck('id', DB::raw('UPPER(name)'));

        return ['LIMA' => (int) ($ids['LIMA'] ?? 0), 'CHICLAYO' => (int) ($ids['CHICLAYO'] ?? 0)];
    }

    /** One row per departure with its figures (reported, else its tickets / parcels). */
    private static function trips(int $cid, string $from, string $to, ?array $warehouses, ?int $vehicleId = null)
    {
        $rows = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')
            ->whereBetween('t.departure_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($warehouses !== null, fn ($q) => $q->whereIn('o.warehouse_id', $warehouses))
            ->when($vehicleId, fn ($q) => $q->where('t.vehicle_id', $vehicleId))
            ->orderBy('t.departure_at')
            ->get(['t.id', 't.vehicle_id', 't.departure_at', 't.fuel_cost', 't.reported_passengers', 't.reported_income', 't.reported_parcels',
                't.reported_parcel_income', 't.reported_excess_income', 't.source', 'r.origin_id', 'v.name as bus', 'v.model as category', 'v.seats', 'v.plate_number']);
        $ids = $rows->pluck('id')->all();
        $tickets = DB::table('transport_tickets')->whereIn('trip_id', $ids ?: [0])->selectRaw('trip_id, COUNT(*) n, SUM(price) s')->groupBy('trip_id')->get()->keyBy('trip_id');
        $parcels = DB::table('transport_parcels')->whereIn('trip_id', $ids ?: [0])->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->selectRaw('trip_id, COUNT(*) n, SUM(price) s')->groupBy('trip_id')->get()->keyBy('trip_id');
        $tolls = DB::table('transport_tolls')->whereIn('trip_id', $ids ?: [0])->whereNull('deleted_at')->selectRaw('trip_id, SUM(amount) s')->groupBy('trip_id')->pluck('s', 'trip_id');

        return $rows->map(function ($t) use ($tickets, $parcels, $tolls) {
            $pax = $t->reported_passengers !== null ? (int) $t->reported_passengers : (int) ($tickets[$t->id]->n ?? 0);
            $tk = $t->reported_passengers !== null ? (float) $t->reported_income : (float) ($tickets[$t->id]->s ?? 0);
            $pc = (float) ($parcels[$t->id]->s ?? 0) + (float) $t->reported_parcel_income;

            return (object) ['id' => (int) $t->id, 'vehicle_id' => (int) $t->vehicle_id, 'bus' => (string) $t->bus, 'category' => (string) $t->category,
                'plate' => (string) $t->plate_number, 'seats' => (int) $t->seats, 'origin_id' => (int) $t->origin_id, 'at' => (string) $t->departure_at,
                'pax' => $pax, 'tickets' => round($tk, 2), 'parcels_n' => (int) ($parcels[$t->id]->n ?? 0) + (int) $t->reported_parcels,
                'parcels' => round($pc, 2), 'excess' => round((float) $t->reported_excess_income, 2),
                'fuel' => $t->fuel_cost !== null ? round((float) $t->fuel_cost, 2) : null, 'toll' => isset($tolls[$t->id]) ? round((float) $tolls[$t->id], 2) : null,
                'source' => $t->source];
        });
    }

    public static function byBus(int $cid, string $from, string $to, ?array $warehouses): array
    {
        $ends = self::ends($cid);
        $trips = self::trips($cid, $from, $to, $warehouses);
        $side = fn ($g) => ['trips' => $g->count(), 'pax' => $g->sum('pax'), 'seats' => $g->sum('seats'), 'tickets' => round($g->sum('tickets'), 2),
            'parcels' => round($g->sum('parcels'), 2), 'excess' => round($g->sum('excess'), 2),
            'total' => round($g->sum('tickets') + $g->sum('parcels') + $g->sum('excess'), 2)];
        $buses = $trips->groupBy('vehicle_id')->map(function ($g) use ($side, $ends) {
            $lima = $side($g->where('origin_id', $ends['LIMA']));
            $cix = $side($g->where('origin_id', $ends['CHICLAYO']));
            $income = round($lima['total'] + $cix['total'], 2);
            $fuel = round($g->sum(fn ($t) => (float) $t->fuel), 2);
            $toll = round($g->sum(fn ($t) => (float) $t->toll), 2);
            $seats = $g->sum('seats');
            $f = $g->first();

            return ['vehicle_id' => $f->vehicle_id, 'bus' => $f->bus, 'category' => $f->category, 'plate' => $f->plate, 'seats' => $f->seats,
                'lima' => $lima, 'chiclayo' => $cix, 'trips' => $g->count(), 'pax' => $g->sum('pax'),
                'occupancy' => $seats ? (int) round($g->sum('pax') / $seats * 100) : null,
                'income' => $income, 'fuel' => $fuel, 'toll' => $toll, 'net' => round($income - $fuel - $toll, 2),
                'missing_fuel' => $g->whereNull('fuel')->count()];
        })->sortBy(fn ($b) => (int) preg_replace('/\D/', '', $b['bus']))->values();
        $sum = fn ($k) => round($buses->sum($k), 2);
        $seats = $trips->sum('seats');

        return ['enabled' => true, 'from' => $from, 'to' => $to, 'buses' => $buses->all(),
            'totals' => ['trips' => $trips->count(), 'pax' => $trips->sum('pax'), 'occupancy' => $seats ? (int) round($trips->sum('pax') / $seats * 100) : null,
                'income' => $sum('income'), 'tickets' => round($trips->sum('tickets'), 2), 'parcels' => round($trips->sum('parcels'), 2),
                'excess' => round($trips->sum('excess'), 2), 'fuel' => $sum('fuel'), 'toll' => $sum('toll'), 'net' => $sum('net'),
                'lima' => $sum(fn ($b) => $b['lima']['total']), 'chiclayo' => $sum(fn ($b) => $b['chiclayo']['total']),
                'missing_fuel' => $buses->sum('missing_fuel')],
            'by_day' => $trips->groupBy(fn ($t) => substr($t->at, 0, 10))->map(fn ($g, $d) => ['d' => $d,
                'income' => round($g->sum('tickets') + $g->sum('parcels') + $g->sum('excess'), 2), 'fuel' => round($g->sum(fn ($t) => (float) $t->fuel), 2),
                'toll' => round($g->sum(fn ($t) => (float) $t->toll), 2), 'pax' => $g->sum('pax')])->values()->all()];
    }

    /** One bus's round trips: each departure from Lima with the next return from Chiclayo (as on the client's sheet). */
    public static function busTrips(int $cid, int $vehicleId, string $from, string $to, ?array $warehouses): array
    {
        $ends = self::ends($cid);
        $trips = self::trips($cid, $from, $to, $warehouses, $vehicleId)->values();
        $rows = [];
        $used = [];
        foreach ($trips as $i => $t) {
            if (isset($used[$t->id])) {
                continue;
            }
            $out = $t->origin_id === $ends['LIMA'] ? $t : null;
            $back = null;
            if ($out) {
                foreach ($trips->slice($i + 1) as $n) {
                    if (! isset($used[$n->id]) && $n->origin_id === $ends['CHICLAYO']) {
                        $back = strtotime($n->at) - strtotime($out->at) < 4 * 86400 ? $n : null;
                        break;
                    }
                    if ($n->origin_id === $ends['LIMA']) {
                        break;
                    }
                }
            } else {
                $back = $t;
            }
            foreach (array_filter([$out, $back]) as $x) {
                $used[$x->id] = true;
            }
            $leg = fn ($x) => $x ? ['id' => $x->id, 'at' => $x->at, 'pax' => $x->pax, 'tickets' => $x->tickets, 'parcels' => $x->parcels, 'excess' => $x->excess,
                'total' => round($x->tickets + $x->parcels + $x->excess, 2), 'source' => $x->source] : null;
            $income = round(($out ? $out->tickets + $out->parcels + $out->excess : 0) + ($back ? $back->tickets + $back->parcels + $back->excess : 0), 2);
            $fuel = round((float) ($out->fuel ?? 0) + (float) ($back->fuel ?? 0), 2);
            $toll = round((float) ($out->toll ?? 0) + (float) ($back->toll ?? 0), 2);
            $rows[] = ['out' => $leg($out), 'back' => $leg($back), 'income' => $income, 'fuel' => $fuel, 'toll' => $toll, 'net' => round($income - $fuel - $toll, 2),
                'fuel_missing' => ($out && $out->fuel === null) || ($back && $back->fuel === null)];
        }
        $f = $trips->first();

        return ['vehicle_id' => $vehicleId, 'bus' => $f->bus ?? '', 'plate' => $f->plate ?? '', 'category' => $f->category ?? '', 'seats' => $f->seats ?? null, 'rows' => $rows];
    }

    public static function daySales(int $cid, string $date, ?array $warehouses): array
    {
        $ends = self::ends($cid);
        // A day without departures: the latest one that has them (never a day ahead).
        $has = fn ($d) => DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('departure_at', $d)->exists();
        if (! $has($date)) {
            $date = substr((string) DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('departure_at', '<=', $date)
                ->where('status', '!=', 'cancelled')->max('departure_at'), 0, 10) ?: $date;
        }
        $trips = self::trips($cid, $date, $date, $warehouses);
        $ids = $trips->pluck('id')->all();
        $points = DB::table('transport_trip_boardings as b')->leftJoin('transport_locations as l', 'l.id', '=', 'b.location_id')->whereIn('b.trip_id', $ids ?: [0])
            ->orderBy('b.seq')->get(['b.trip_id', 'b.label', 'l.name', 'b.board_at'])->groupBy('trip_id');
        $half = fn ($origin) => $trips->where('origin_id', $origin)->map(fn ($t) => [
            'id' => $t->id, 'bus' => $t->bus, 'seats' => $t->seats, 'pax' => $t->pax, 'free' => $t->seats ? max(0, $t->seats - $t->pax) : null,
            'points' => isset($points[$t->id]) ? $points[$t->id]->map(fn ($p) => ['name' => $p->label ?: $p->name, 'at' => $p->board_at])->values()->all()
                : [['name' => '', 'at' => $t->at]],
            'amount' => $t->tickets, 'parcels' => $t->parcels, 'excess' => $t->excess, 'toll' => $t->toll, 'fuel' => $t->fuel, 'source' => $t->source,
        ])->sortBy(fn ($r) => $r['points'][0]['at'])->values()->all();
        $channels = [];
        foreach (DB::table('transport_aurasoft_rows')->where('company_id', $cid)->where('is_current', 1)->whereIn('trip_id', $ids ?: [0])->get(['trip_id', 'channels']) as $r) {
            $origin = $trips->firstWhere('id', (int) $r->trip_id)->origin_id ?? 0;
            $sideKey = $origin === $ends['LIMA'] ? 'lima' : 'chiclayo';
            foreach (json_decode((string) $r->channels, true) ?: [] as $k => $n) {
                $channels[$sideKey][$k] = ($channels[$sideKey][$k] ?? 0) + (int) $n;
            }
        }
        $prev = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('departure_at', '<', $date)->max('departure_at');
        $next = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('departure_at', '>', $date)
            ->whereDate('departure_at', '<=', now()->toDateString())->min('departure_at');

        return ['enabled' => true, 'date' => $date, 'prev' => $prev ? substr($prev, 0, 10) : null, 'next' => $next ? substr($next, 0, 10) : null,
            'lima' => $half($ends['LIMA']), 'chiclayo' => $half($ends['CHICLAYO']), 'channels' => $channels];
    }
}
