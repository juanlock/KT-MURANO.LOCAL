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
                't.reported_parcel_income', 't.reported_excess_income', 't.source', 't.route_id', 'r.origin_id', 'r.toll_cost as route_toll',
                'o.name as origin', 'v.name as bus', 'v.model as category', 'v.seats', 'v.plate_number']);
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
                'fuel' => $t->fuel_cost !== null ? round((float) $t->fuel_cost, 2) : null,
                // No toll recorded for the departure: the route's toll (2026-11-24, Mayte's S/ 329.30 a stretch), flagged.
                'toll' => isset($tolls[$t->id]) ? round((float) $tolls[$t->id], 2) : ($t->route_toll !== null ? round((float) $t->route_toll, 2) : null),
                'toll_est' => ! isset($tolls[$t->id]) && $t->route_toll !== null,
                'route_id' => (int) $t->route_id, 'origin' => (string) $t->origin, 'source' => $t->source];
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
        // Plates of AuraSoft / Jelaf that are not a bus of Murano (2026-11-26: C7P959 with 90 departures in 2026, AMP868): their
        // departures are not Murano departures (a departure needs its bus), so they come straight from the imported rows, in red
        // on the board ("bus no registrado") until Operaciones says which bus it is (project questions bus_c7p959_*).
        $unreg = DB::table('transport_aurasoft_rows')->where('company_id', $cid)->where('is_current', 1)->whereNull('trip_id')->whereNull('vehicle_id')
            ->whereBetween('trip_date', [$from, $to])->get(['plate', 'origin', 'seats', 'ticket_income', 'parcel_income', 'excess_income']);
        foreach ($unreg->groupBy('plate') as $plate => $g) {
            $part = function ($rows) {
                $t = round($rows->sum('ticket_income'), 2);
                $p = round($rows->sum('parcel_income'), 2);
                $e = round($rows->sum('excess_income'), 2);

                return ['trips' => $rows->count(), 'pax' => (int) $rows->sum('seats'), 'seats' => 0, 'tickets' => $t, 'parcels' => $p, 'excess' => $e, 'total' => round($t + $p + $e, 2)];
            };
            $lima = $part($g->filter(fn ($r) => stripos((string) $r->origin, 'LIMA') !== false));
            $cix = $part($g->reject(fn ($r) => stripos((string) $r->origin, 'LIMA') !== false));
            $income = round($lima['total'] + $cix['total'], 2);
            $buses->push(['vehicle_id' => 'x-'.$plate, 'bus' => $plate, 'category' => __('Bus not registered'), 'plate' => $plate, 'seats' => null,
                'lima' => $lima, 'chiclayo' => $cix, 'trips' => $g->count(), 'pax' => (int) $g->sum('seats'), 'occupancy' => null,
                'income' => $income, 'fuel' => 0, 'toll' => 0, 'net' => $income, 'missing_fuel' => 0, 'unregistered' => true]);
        }
        $sum = fn ($k) => round($buses->sum($k), 2);
        $seats = $trips->sum('seats');

        return ['enabled' => true, 'from' => $from, 'to' => $to, 'buses' => $buses->all(),
            'totals' => ['trips' => $trips->count(), 'pax' => $trips->sum('pax'), 'occupancy' => $seats ? (int) round($trips->sum('pax') / $seats * 100) : null,
                'income' => $sum('income'), 'tickets' => round($trips->sum('tickets'), 2), 'parcels' => round($trips->sum('parcels'), 2),
                'excess' => round($trips->sum('excess'), 2), 'fuel' => $sum('fuel'), 'toll' => $sum('toll'), 'net' => $sum('net'),
                'lima' => $sum(fn ($b) => $b['lima']['total']), 'chiclayo' => $sum(fn ($b) => $b['chiclayo']['total']),
                'missing_fuel' => $buses->sum('missing_fuel')],
            // By service type (the bus's category: CONFORT, MASTER, PREMIUM, EJECUTIVO...), 2026-11-24: what each kind yields.
            'by_service' => $buses->groupBy(fn ($b) => mb_strtoupper(trim((string) $b['category'])) ?: '—')->map(function ($g, $k) use ($trips) {
                $ids = $g->pluck('vehicle_id')->all();
                $tt = $trips->whereIn('vehicle_id', $ids);
                $seats = $tt->sum('seats');
                $n = max(1, $tt->count());

                return ['service' => $k, 'buses' => $g->count(), 'trips' => $tt->count(), 'pax' => $tt->sum('pax'),
                    'occupancy' => $seats ? (int) round($tt->sum('pax') / $seats * 100) : null,
                    'income' => round($g->sum('income'), 2), 'net' => round($g->sum('net'), 2),
                    'income_trip' => round($g->sum('income') / $n, 2), 'net_trip' => round($g->sum('net') / $n, 2),
                    'ticket_avg' => $tt->sum('pax') ? round($tt->sum('tickets') / $tt->sum('pax'), 2) : null];
            })->sortByDesc('income_trip')->values()->all(),
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
                'total' => round($x->tickets + $x->parcels + $x->excess, 2), 'fuel' => $x->fuel, 'toll' => $x->toll, 'toll_est' => $x->toll_est,
                'source' => $x->source] : null;
            $income = round(($out ? $out->tickets + $out->parcels + $out->excess : 0) + ($back ? $back->tickets + $back->parcels + $back->excess : 0), 2);
            $fuel = round((float) ($out->fuel ?? 0) + (float) ($back->fuel ?? 0), 2);
            $toll = round((float) ($out->toll ?? 0) + (float) ($back->toll ?? 0), 2);
            $rows[] = ['out' => $leg($out), 'back' => $leg($back), 'income' => $income, 'fuel' => $fuel, 'toll' => $toll, 'net' => round($income - $fuel - $toll, 2),
                'fuel_missing' => ($out && $out->fuel === null) || ($back && $back->fuel === null),
                'toll_est' => ($out && $out->toll_est) || ($back && $back->toll_est)];
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
            ->orderBy('b.seq')->get(['b.trip_id', 'b.label', 'l.name', 'b.board_at'])->groupBy('trip_id')
            ->map(fn ($g) => $g->map(fn ($p) => ['name' => $p->label ?: $p->name, 'at' => $p->board_at])->values()->all())->all();
        $timetable = [];
        foreach ($trips as $t) {
            if (! isset($points[$t->id]) && ($tpl = self::timetablePoints($cid, $t))) {
                $points[$t->id] = $tpl;
                $timetable[$t->id] = true;
            }
        }
        $half = fn ($origin) => $trips->where('origin_id', $origin)->map(fn ($t) => [
            'id' => $t->id, 'bus' => $t->bus, 'category' => $t->category, 'seats' => $t->seats, 'pax' => $t->pax, 'free' => $t->seats ? max(0, $t->seats - $t->pax) : null,
            'points' => $points[$t->id] ?? [['name' => '', 'at' => $t->at]], 'points_timetable' => isset($timetable[$t->id]),
            'amount' => $t->tickets, 'parcels' => $t->parcels, 'excess' => $t->excess, 'toll' => $t->toll, 'toll_est' => $t->toll_est,
            'fuel' => $t->fuel, 'source' => $t->source,
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

    /**
     * A departure without boarding points of its own (AuraSoft does not send them): those of the latest earlier departure of
     * the same route at the same time of day (± 20 min) that has them, moved to this departure's date and time (2026-11-24).
     */
    private static function timetablePoints(int $cid, object $t): ?array
    {
        $at = strtotime($t->at);
        $mins = (int) date('G', $at) * 60 + (int) date('i', $at);
        $tpl = DB::table('transport_trips as t')->where('t.company_id', $cid)->whereNull('t.deleted_at')->where('t.route_id', $t->route_id)
            ->where('t.departure_at', '<', $t->at)->whereRaw('ABS(HOUR(t.departure_at) * 60 + MINUTE(t.departure_at) - ?) <= 20', [$mins])
            ->whereExists(fn ($q) => $q->from('transport_trip_boardings as b')->whereColumn('b.trip_id', 't.id'))
            ->orderByDesc('t.departure_at')->first(['t.id', 't.departure_at']);
        if (! $tpl) {
            return null;
        }
        $shift = $at - strtotime($tpl->departure_at);

        return DB::table('transport_trip_boardings as b')->leftJoin('transport_locations as l', 'l.id', '=', 'b.location_id')->where('b.trip_id', $tpl->id)
            ->orderBy('b.seq')->get(['b.label', 'l.name', 'b.board_at'])
            ->map(fn ($p) => ['name' => $p->label ?: $p->name, 'at' => $p->board_at ? date('Y-m-d H:i:s', strtotime($p->board_at) + $shift) : $t->at])->all();
    }

    /**
     * Mayte types a round trip's fuel (2026-11-24: the one figure AuraSoft does not have). Shared half and half between the
     * departure from Lima and its return, like the client's sheet (all on one when there is no other); null clears it.
     */
    public static function saveFuel(int $cid, array $tripIds, ?float $amount): void
    {
        $ids = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('id', $tripIds ?: [0])->pluck('id')->all();
        abort_unless($ids && count($ids) === count(array_unique($tripIds)), 404, __('Departure not found.'));
        DB::transaction(function () use ($ids, $amount) {
            $n = count($ids);
            foreach ($ids as $i => $id) {
                // The cents left by the split go on the first one.
                $part = $amount === null ? null : ($i === 0 ? round($amount - round($amount / $n, 2) * ($n - 1), 2) : round($amount / $n, 2));
                DB::table('transport_trips')->where('id', $id)->update(['fuel_cost' => $part, 'updated_at' => now()]);
            }
        });
    }

    /**
     * "Completar el reporte" (2026-11-24, Ventas del día): every round trip of the period, all buses, with what is still
     * missing (fuel) -- the missing ones first, then by date. Each row carries its trip ids for saveFuel().
     */
    public static function pending(int $cid, string $from, string $to, ?array $warehouses): array
    {
        $rows = [];
        foreach (self::byBus($cid, $from, $to, $warehouses)['buses'] as $b) {
            foreach (self::busTrips($cid, (int) $b['vehicle_id'], $from, $to, $warehouses)['rows'] as $r) {
                $rows[] = ['bus' => preg_replace('/^Bus\s+/i', '', $b['bus']), 'plate' => $b['plate'], 'out' => $r['out'], 'back' => $r['back'],
                    'income' => $r['income'], 'fuel' => $r['fuel'] ?: null, 'toll' => $r['toll'], 'toll_est' => $r['toll_est'], 'fuel_missing' => $r['fuel_missing'],
                    'trip_ids' => array_values(array_filter([$r['out']['id'] ?? null, $r['back']['id'] ?? null])),
                    'at' => $r['out']['at'] ?? $r['back']['at'] ?? ''];
            }
        }
        usort($rows, fn ($a, $b) => [$b['fuel_missing'], $a['at']] <=> [$a['fuel_missing'], $b['at']]);

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'missing' => count(array_filter($rows, fn ($r) => $r['fuel_missing']))];
    }

    // ---------------------------------------------------------------- the client's two Excel files

    private static function companyTitle(int $cid): string
    {
        return mb_strtoupper((string) DB::table('companies')->where('id', $cid)->value('name'));
    }

    /** "PRODUCCION POR BUS" as Mayte keeps it: the summary "REPORTE DE PROC. X BUSES" and one sheet per bus (round trips). */
    public static function productionXlsx(int $cid, string $from, string $to, ?array $warehouses): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sum = $book->getActiveSheet()->setTitle('REPORTE DE PROC. X BUSES');
        $data = self::byBus($cid, $from, $to, $warehouses);
        $period = date('d/m/Y', strtotime($from)).' AL '.date('d/m/Y', strtotime($to));
        $sum->setCellValue('A1', self::companyTitle($cid))->mergeCells('A1:M1');
        $sum->setCellValue('E2', 'LIMA')->mergeCells('E2:H2')->setCellValue('I2', 'CHICLAYO')->mergeCells('I2:L2');
        $sum->fromArray(['FECHA DE REPORTE', 'ITEM', 'T. SERVICIO', 'BUS', 'PASAJES', 'ENCOMIENDA', 'EXCESO', 'TOTAL', 'PASAJES', 'ENCOMIENDA', 'EXCESO', 'TOTAL', 'TOTAL INGRESOS'], null, 'A3');
        $r = 4;
        foreach ($data['buses'] as $i => $b) {
            $sum->fromArray([$period, $i + 1, mb_strtoupper($b['category']), preg_replace('/^Bus\s+/i', '', $b['bus']),
                $b['lima']['tickets'], $b['lima']['parcels'], $b['lima']['excess'], $b['lima']['total'],
                $b['chiclayo']['tickets'], $b['chiclayo']['parcels'], $b['chiclayo']['excess'], $b['chiclayo']['total'], $b['income']], null, 'A'.$r++);
        }
        $sum->setCellValue('A'.$r, 'TOTAL')->mergeCells("A{$r}:D{$r}");
        foreach (range('E', 'M') as $c) {
            $sum->setCellValue($c.$r, "=SUM({$c}4:{$c}".($r - 1).')');
        }
        self::style($sum, 'A3:M3', "A{$r}:M{$r}", 'E4:M'.$r, [2]);

        foreach ($data['buses'] as $b) {
            $trips = self::busTrips($cid, (int) $b['vehicle_id'], $from, $to, $warehouses);
            $name = mb_substr(preg_replace('/^Bus\s+/i', '', $b['bus']) ?: 'Bus', 0, 31);
            $sh = $book->createSheet()->setTitle($book->sheetNameExists($name) ? mb_substr($name.' '.$b['vehicle_id'], 0, 31) : $name);
            $sh->setCellValue('B1', self::companyTitle($cid))->mergeCells('B1:L1');
            $sh->setCellValue('A2', 'SALIDAS DE LIMA')->mergeCells('A2:L2')->setCellValue('N2', 'SALIDAS CHICLAYO')->mergeCells('N2:W2');
            $sh->fromArray(['BUS', 'PLACA', 'FECHA', 'HORARIO', 'RUTA', 'CNT ASI.', 'TOTAL PAX', 'TOTAL ENCO', 'TOTAL EXC', 'INGRESO LIMA', 'COMBUSTIBLE', 'PEAJE', null,
                'FECHA', 'HORARIO', 'RUTA', 'CNT ASI.', 'TOTAL PAX', 'TOTAL ENCO', 'TOTAL EXC', 'INGRESO CIX', 'INGRESO TOTAL LIMA-CIX', 'MONTO LIMA+CIX-PEAJE-COMB',
                null, 'FUENTE LIMA', 'FUENTE CIX'], null, 'A4');
            $row = 5;
            $bus = preg_replace('/^Bus\s+/i', '', $b['bus']);
            foreach ($trips['rows'] as $t) {
                $o = $t['out'];
                $k = $t['back'];
                $sh->fromArray([
                    $bus, $b['plate'], $o ? date('d/m/Y', strtotime($o['at'])) : null, $o ? date('h:iA', strtotime($o['at'])) : null, $o ? 'LIMA / CHICLAYO' : null,
                    $o['pax'] ?? null, $o['tickets'] ?? null, $o['parcels'] ?? null, $o['excess'] ?? null, $o['total'] ?? null, $t['fuel'] ?: null, $t['toll'] ?: null, null,
                    $k ? date('d/m/Y', strtotime($k['at'])) : null, $k ? date('h:iA', strtotime($k['at'])) : null, $k ? 'CHICLAYO / LIMA' : null,
                    $k['pax'] ?? null, $k['tickets'] ?? null, $k['parcels'] ?? null, $k['excess'] ?? null, $k['total'] ?? null, $t['income'], $t['net'],
                    null, $o ? self::sourceLabel($o['source'] ?? null) : null, $k ? self::sourceLabel($k['source'] ?? null) : null,
                ], null, 'A'.$row++);
            }
            $sh->setCellValue('A'.$row, 'TOTAL');
            foreach (['F', 'G', 'H', 'I', 'J', 'K', 'L', 'Q', 'R', 'S', 'T', 'U', 'V', 'W'] as $c) {
                $sh->setCellValue($c.$row, "=SUM({$c}5:{$c}".($row - 1).')');
            }
            self::style($sh, 'A4:W4', "A{$row}:W{$row}", 'G5:W'.$row, [2]);
            $sh->getStyle('F5:F'.$row)->getNumberFormat()->setFormatCode('0');
            $sh->getStyle('Q5:Q'.$row)->getNumberFormat()->setFormatCode('0');
            $sh->getColumnDimension('M')->setWidth(2);
            $sh->getColumnDimension('X')->setWidth(2);
            $sh->getColumnDimension('Y')->setWidth(34);
            $sh->getColumnDimension('Z')->setWidth(34);
            $sh->getStyle('Y4:Z4')->getFont()->setBold(true);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * Where a departure's figures come from (2026-11-25, Mayte: "¿por qué el bus 22 no tiene encomiendas ni exceso antes del
     * 19-11, de dónde sacó eso?"): only AuraSoft's «Reporte producción por bus» brings parcels and excess; a departure from the
     * client's daily report has passengers and amount only.
     */
    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            'aurasoft' => 'AuraSoft (Reporte producción por bus)',
            'report' => 'Reporte del día (sin encomienda ni exceso)',
            default => 'Murano (POS / salidas del día)',
        };
    }

    /** "REPORTE DEL DIA VENTAS" as Mayte keeps it: one sheet per day ("22-11"), Lima on the left, Chiclayo on the right. */
    public static function daySalesXlsx(int $cid, string $from, string $to, ?array $warehouses): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex(0);
        $days = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->whereBetween('departure_at', [$from.' 00:00:00', $to.' 23:59:59'])->selectRaw('DATE(departure_at) d')->distinct()->orderBy('d')->pluck('d');
        $head = ['CANTIDAD DE ASIENTOS', 'BUS', 'PUNTOS DE EMBARQUE', 'HORARIO', 'CANT PASAJEROS', 'ASIENTOS LIBRES', 'MONTO X BUS', 'PEAJE', 'COMBUSTIBLE', 'ENCOMIENDA', 'EXCESO'];
        foreach ($days as $d) {
            $day = self::daySales($cid, (string) $d, $warehouses);
            $sh = $book->createSheet()->setTitle(date('d-m', strtotime($d)));
            $sh->setCellValue('B1', self::companyTitle($cid))->mergeCells('B1:X1');
            $dm = date('d/m', strtotime($d));
            $sh->setCellValue('B3', "REPORTE DE SALIDAS LIMA $dm")->mergeCells('B3:L3')->setCellValue('N3', "REPORTE DE SALIDAS CIX $dm")->mergeCells('N3:X3');
            $sh->fromArray($head, null, 'B5');
            $sh->fromArray($head, null, 'N5');
            $last = 5;
            foreach (['B' => $day['lima'], 'N' => $day['chiclayo']] as $col => $rows) {
                $c = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($col);
                $r = 6;
                foreach ($rows as $t) {
                    $first = $r;
                    foreach ($t['points'] as $k => $p) {
                        $sh->setCellValue([$c + 2, $r], $p['name'] ?: null);
                        $sh->setCellValue([$c + 3, $r], $p['at'] ? date('h:iA', strtotime($p['at'])) : null);
                        if ($k === 0) {
                            $sh->fromArray([$t['seats'] ?: null, preg_replace('/^Bus\s+/i', '', $t['bus'])], null, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$r);
                            $sh->fromArray([$t['pax'], $t['free'], $t['amount'], $t['toll'], $t['fuel'], $t['parcels'] ?: null, $t['excess'] ?: null], null,
                                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 4).$r);
                        }
                        $r++;
                    }
                    if ($r - 1 > $first) {   // the bus's figures span its boarding points, as on her sheet
                        foreach ([0, 1, 4, 5, 6, 7, 8, 9, 10] as $off) {
                            $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + $off);
                            $sh->mergeCells("{$L}{$first}:{$L}".($r - 1));
                        }
                    }
                }
                $sh->setCellValue([$c + 1, $r], 'TOTAL');
                foreach ([0, 4, 5, 6, 7, 8, 9, 10] as $off) {
                    $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + $off);
                    $sh->setCellValue($L.$r, "=SUM({$L}6:{$L}".($r - 1).')');
                }
                $last = max($last, $r);
            }
            self::style($sh, 'B5:X5', null, 'H6:L'.$last, [1, 3]);
            $sh->getStyle('T6:X'.$last)->getNumberFormat()->setFormatCode('#,##0.00');
            foreach (['B', 'N'] as $col) {
                $c = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($col);
                $sh->getStyle(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).'6:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 10).$last)
                    ->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            }
            $sh->getColumnDimension('M')->setWidth(2);
            $sh->getStyle('B'.$last.':X'.$last)->getFont()->setBold(true);
        }
        if (! $book->getSheetCount()) {
            $book->createSheet()->setTitle('SIN SALIDAS')->setCellValue('A1', 'Sin salidas en el período.');
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** Header bold on blue, totals bold, money with 2 decimals, widths to fit, the title rows bold. */
    private static function style($sh, string $header, ?string $total, string $money, array $titleRows): void
    {
        $sh->getStyle($header)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sh->getStyle($header)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1F4E78');
        $sh->getStyle($header)->getAlignment()->setWrapText(true)->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        if ($total) {
            $sh->getStyle($total)->getFont()->setBold(true);
            $sh->getStyle($total)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        }
        $sh->getStyle($money)->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (array_merge([1], $titleRows) as $tr) {
            $sh->getStyle($tr.':'.$tr)->getFont()->setBold(true);
            $sh->getStyle($tr.':'.$tr)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }
        foreach ($sh->getColumnIterator() as $col) {
            $sh->getColumnDimension($col->getColumnIndex())->setWidth(13);
        }
        $sh->freezePane(preg_replace('/^([A-Z]+)(\d+).*/', '$1', $header).((int) preg_replace('/^[A-Z]+(\d+).*/', '$1', $header) + 1));
    }
}
