<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Turismo Cautivo's own Excel reports (Mayte, 2026-11-23), read into Murano's departures:
 *
 *  - "REPORTE DEL DIA VENTAS": one sheet per day ("12-11"), two halves -- departures from Lima and from Chiclayo --, one
 *    block per bus: bus, boarding points with their time (continuation rows), passengers, amount, and on the Lima half
 *    the round trip's toll and fuel, parcels and excess. readDaily() / applyDaily().
 *  - "PRODUCCION POR BUS": one sheet per bus ("170"), a row = a Lima -> Chiclayo departure and its return, with the round
 *    trip's fuel and toll. readProduction() / applyProduction().
 *
 * Rules: AuraSoft's figures (passengers, money) prevail (AuraSoftTrips); these reports bring the boarding points, the fuel
 * and the tolls, and make the departures of the days AuraSoft has not brought (source = report). A round trip's fuel and
 * toll are shared half and half between the Lima departure and the bus's return from Chiclayo (all on the first one when
 * the return is not found). Toll rows written here carry place = "Reporte del cliente" and are replaced on every run.
 */
class TransportClientReports
{
    /** The report's boarding codes -> Murano's places. */
    private const POINTS = ['P.N.' => 'PLAZA LIMA NORTE', 'PN' => 'PLAZA LIMA NORTE', 'CV' => 'CAVASSA', 'T.OLIVOS' => 'LOS OLIVOS', 'OLIVOS' => 'LOS OLIVOS',
        'CIX' => 'CHICLAYO', 'T.CIX' => 'CHICLAYO', 'CHEPEN' => 'CHEPEN', 'TRUX' => 'TRUJILLO', 'TRUJILLO' => 'TRUJILLO', 'LAMBAYEQUE' => 'LAMBAYEQUE'];

    public const TOLL_PLACE = 'Reporte del cliente';

    // ---------------------------------------------------------------- daily sales report

    /** @return array<int, array{date:string, dir:string, bus:string, points:array, pax:?int, amount:?float, toll:?float, fuel:?float, parcels:?float, excess:?float}> */
    public static function readDaily(string $path, int $year): array
    {
        $book = IOFactory::load($path);
        $out = [];
        foreach ($book->getAllSheets() as $sh) {
            if (! preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($sh->getTitle()), $m)) {
                continue;
            }
            $date = sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
            $rows = $sh->toArray(null, true, true, false);
            // The header row: the one with two "BUS" cells.
            $head = null;
            foreach ($rows as $i => $r) {
                $buses = array_keys(array_filter($r, fn ($v) => mb_strtoupper(trim((string) $v)) === 'BUS'));
                if (count($buses) >= 2) {
                    $head = $i;
                    break;
                }
            }
            if ($head === null) {
                continue;
            }
            $h = array_map(fn ($v) => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $v))), $rows[$head]);
            $busCols = array_keys(array_filter($h, fn ($v) => $v === 'BUS'));
            $split = $busCols[1];
            $find = function (string $re, int $from, int $to) use ($h) {
                foreach ($h as $c => $v) {
                    if ($c >= $from && $c < $to && preg_match($re, $v)) {
                        return $c;
                    }
                }

                return null;
            };
            $sides = [];
            foreach (['LC' => [0, $split], 'CL' => [$split, count($h) + 5]] as $dir => [$a, $b]) {
                $bus = $dir === 'LC' ? $busCols[0] : $split;
                $sides[$dir] = [
                    'bus' => $bus, 'point' => $find('/PUNTOS/', $a, $b), 'time' => $find('/HORARIO/', $a, $b), 'pax' => $find('/PASAJEROS/', $a, $b),
                    'toll' => $find('/PEAJE/', $a, $b), 'fuel' => $find('/COMBUSTIBLE/', $a, $b), 'parcels' => $find('/ENCOMIENDA/', $a, $b),
                    'excess' => $find('/EXCESO/', $a, $b), 'free' => $find('/LIBRE/', $a, $b),
                ];
                // The amount: "MONTO..." in that half; on the Chiclayo half it may have no header -- the column after "ASIENTOS LIBRES".
                $sides[$dir]['amount'] = $find('/^MONTO/', $a, $b) ?? ($sides[$dir]['free'] !== null ? $sides[$dir]['free'] + 1 : null);
            }
            foreach ($sides as $dir => $c) {
                $cur = null;
                foreach (array_slice($rows, $head + 1, null, true) as $r) {
                    $bus = trim((string) ($r[$c['bus']] ?? ''));
                    $point = $c['point'] !== null ? trim((string) ($r[$c['point']] ?? '')) : '';
                    $time = $c['time'] !== null ? self::time((string) ($r[$c['time']] ?? '')) : null;
                    if (preg_match('/^\d{3}$/', $bus)) {
                        if ($cur) {
                            $out[] = $cur;
                        }
                        $cur = ['date' => $date, 'dir' => $dir, 'bus' => $bus, 'points' => [], 'pax' => self::int($r[$c['pax']] ?? null),
                            'amount' => self::num($r[$c['amount']] ?? null), 'toll' => self::num($r[$c['toll']] ?? null), 'fuel' => self::num($r[$c['fuel']] ?? null),
                            'parcels' => self::num($r[$c['parcels']] ?? null), 'excess' => self::num($r[$c['excess']] ?? null)];
                    } elseif (! $cur || $bus !== '') {
                        if ($bus !== '' && $cur) { // a total / note row ends the block
                            $out[] = $cur;
                            $cur = null;
                        }
                        continue;
                    }
                    if ($point !== '' && $time) {
                        $cur['points'][] = [mb_strtoupper($point), $time];
                    }
                }
                if ($cur) {
                    $out[] = $cur;
                }
            }
        }

        return $out;
    }

    /** Applies the daily report to the company's departures. Returns counts. */
    public static function applyDaily(int $cid, array $items): array
    {
        $out = ['matched' => 0, 'created' => 0, 'boardings' => 0, 'fuel' => 0, 'tolls' => 0, 'no_bus' => [], 'no_route' => 0];
        [$routes, $buses, $places] = self::refs($cid);

        return DB::transaction(function () use ($cid, $items, $routes, $buses, $places, $out) {
            foreach ($items as $it) {
                $vid = $buses['BUS '.$it['bus']] ?? null;
                $route = $routes[$it['dir']] ?? null;
                if (! $vid) {
                    $out['no_bus'][$it['bus']] = true;
                    continue;
                }
                if (! $route) {
                    $out['no_route']++;
                    continue;
                }
                $first = $it['points'][0][1] ?? '00:00:00';
                $trip = self::tripOn($cid, $vid, $route->id, $it['date']);
                if ($trip) {
                    $out['matched']++;
                } elseif ($it['pax'] !== null || $it['amount'] !== null) {
                    $dep = Carbon::parse($it['date'].' '.$first);
                    $id = DB::table('transport_trips')->insertGetId([
                        'company_id' => $cid, 'route_id' => $route->id, 'vehicle_id' => $vid, 'departure_at' => $dep,
                        'arrival_at' => $route->duration_min ? $dep->copy()->addMinutes((int) $route->duration_min) : null,
                        'status' => $dep->isPast() ? 'completed' : 'scheduled', 'notes' => 'Reporte del día', 'source' => 'report',
                        'reported_passengers' => $it['pax'] ?? 0, 'reported_income' => $it['amount'] ?? 0,
                        'reported_parcel_income' => $it['parcels'], 'reported_excess_income' => $it['excess'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $trip = DB::table('transport_trips')->where('id', $id)->first();
                    $out['created']++;
                } else {
                    continue;
                }
                // Boarding points (replaced).
                if ($it['points']) {
                    DB::table('transport_trip_boardings')->where('trip_id', $trip->id)->delete();
                    foreach ($it['points'] as $n => [$code, $time]) {
                        $loc = $places[self::POINTS[$code] ?? $code] ?? ($it['dir'] === 'LC' ? $places['LIMA'] : $places['CHICLAYO']) ?? null;
                        $at = Carbon::parse($it['date'].' '.$time);
                        if ($n > 0 && $at->lt(Carbon::parse($it['date'].' '.$it['points'][0][1]))) {
                            $at->addDay(); // a stop after midnight
                        }
                        DB::table('transport_trip_boardings')->insert(['company_id' => $cid, 'trip_id' => $trip->id, 'location_id' => $loc,
                            'label' => $code, 'seq' => $n, 'board_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
                        $out['boardings']++;
                    }
                }
                if ($it['dir'] === 'LC' && ($it['fuel'] || $it['toll'])) {
                    $r = self::roundTrip($cid, $trip, $routes['CL']->id ?? null, $it['fuel'], $it['toll']);
                    $out['fuel'] += $r['fuel'];
                    $out['tolls'] += $r['tolls'];
                }
            }
            $out['no_bus'] = array_keys($out['no_bus']);

            return $out;
        });
    }

    // ---------------------------------------------------------------- production per bus

    /** @return array<int, array{bus:string, out_date:string, out_time:?string, back_date:?string, back_time:?string, fuel:?float, toll:?float}> */
    public static function readProduction(string $path): array
    {
        $book = IOFactory::load($path);
        $out = [];
        foreach ($book->getAllSheets() as $sh) {
            if (! preg_match('/^\d{3}$/', trim($sh->getTitle()))) {
                continue;
            }
            $bus = trim($sh->getTitle());
            $rows = $sh->toArray(null, true, true, false);
            $head = null;
            foreach ($rows as $i => $r) {
                $u = array_map(fn ($v) => mb_strtoupper(trim((string) $v)), $r);
                if (in_array('PLACA', $u, true) && in_array('COMBUSTIBLE', $u, true)) {
                    $head = $i;
                    break;
                }
            }
            if ($head === null) {
                continue;
            }
            $h = array_map(fn ($v) => mb_strtoupper(trim((string) $v)), $rows[$head]);
            $dates = array_keys(array_filter($h, fn ($v) => $v === 'FECHA'));
            $times = array_keys(array_filter($h, fn ($v) => $v === 'HORARIO'));
            $fuel = array_search('COMBUSTIBLE', $h, true);
            $toll = array_search('PEAJE', $h, true);
            foreach (array_slice($rows, $head + 1) as $r) {
                $d1 = self::date((string) ($r[$dates[0]] ?? ''));
                if (! $d1) {
                    continue;
                }
                $out[] = ['bus' => $bus, 'out_date' => $d1, 'out_time' => self::time((string) ($r[$times[0]] ?? '')),
                    'back_date' => isset($dates[1]) ? self::date((string) ($r[$dates[1]] ?? '')) : null,
                    'back_time' => isset($times[1]) ? self::time((string) ($r[$times[1]] ?? '')) : null,
                    'fuel' => self::num($r[$fuel] ?? null), 'toll' => self::num($r[$toll] ?? null)];
            }
        }

        return $out;
    }

    /** Fuel and tolls of each round trip of the production report (where the daily report brought none). */
    public static function applyProduction(int $cid, array $items, bool $overwrite = false): array
    {
        $out = ['round_trips' => 0, 'fuel' => 0, 'tolls' => 0, 'no_trip' => 0];
        [$routes, $buses] = self::refs($cid);

        return DB::transaction(function () use ($cid, $items, $routes, $buses, $overwrite, $out) {
            foreach ($items as $it) {
                $vid = $buses['BUS '.$it['bus']] ?? null;
                $trip = $vid && isset($routes['LC']) ? self::tripOn($cid, $vid, $routes['LC']->id, $it['out_date']) : null;
                if (! $trip) {
                    $out['no_trip']++;
                    continue;
                }
                if (! $overwrite && $trip->fuel_cost !== null) {
                    continue;
                }
                $r = self::roundTrip($cid, $trip, $routes['CL']->id ?? null, $it['fuel'], $it['toll'], $it['back_date']);
                $out['round_trips']++;
                $out['fuel'] += $r['fuel'];
                $out['tolls'] += $r['tolls'];
            }

            return $out;
        });
    }

    // ---------------------------------------------------------------- shared

    /** Half the round trip's fuel and toll on the Lima departure, half on the bus's return from Chiclayo. */
    private static function roundTrip(int $cid, object $trip, ?int $backRoute, ?float $fuel, ?float $toll, ?string $backDate = null): array
    {
        $back = null;
        if ($backRoute) {
            $q = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->where('vehicle_id', $trip->vehicle_id)->where('route_id', $backRoute);
            $back = $backDate ? (clone $q)->whereDate('departure_at', $backDate)->first() : null;
            $back = $back ?: $q->where('departure_at', '>', $trip->departure_at)->where('departure_at', '<', Carbon::parse($trip->departure_at)->addHours(72))->orderBy('departure_at')->first();
        }
        $legs = array_values(array_filter([$trip, $back]));
        $n = count($legs);
        $done = ['fuel' => 0, 'tolls' => 0];
        foreach ($legs as $leg) {
            if ($fuel) {
                DB::table('transport_trips')->where('id', $leg->id)->update(['fuel_cost' => round($fuel / $n, 2), 'updated_at' => now()]);
                $done['fuel']++;
            }
            if ($toll) {
                DB::table('transport_tolls')->where('trip_id', $leg->id)->where('place', self::TOLL_PLACE)->delete();
                DB::table('transport_tolls')->insert(['company_id' => $cid, 'trip_id' => $leg->id, 'place' => self::TOLL_PLACE, 'amount' => round($toll / $n, 2),
                    'paid_on' => substr((string) $leg->departure_at, 0, 10), 'created_at' => now(), 'updated_at' => now()]);
                $done['tolls']++;
            }
        }

        return $done;
    }

    /** The bus's departure on that route that day (a bus does one a day each way). */
    private static function tripOn(int $cid, int $vid, int $routeId, string $date): ?object
    {
        return DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->where('vehicle_id', $vid)->where('route_id', $routeId)
            ->whereDate('departure_at', $date)->orderByRaw("source = 'aurasoft' desc")->orderBy('departure_at')->first();
    }

    /** [routes LC / CL, buses by upper name, places by name]. */
    private static function refs(int $cid): array
    {
        $places = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtoupper($n) => $id])->all();
        $route = fn ($o, $d) => isset($places[$o], $places[$d])
            ? DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')->where('origin_id', $places[$o])->where('destination_id', $places[$d])->first() : null;
        $routes = array_filter(['LC' => $route('LIMA', 'CHICLAYO'), 'CL' => $route('CHICLAYO', 'LIMA')]);
        $buses = DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtoupper($n) => $id])->all();

        return [$routes, $buses, $places];
    }

    private static function num($v): ?float
    {
        $s = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $v));

        return $s === '' || $s === '-' || $s === '.' ? null : round((float) $s, 2);
    }

    private static function int($v): ?int
    {
        $n = self::num($v);

        return $n === null ? null : (int) $n;
    }

    private static function date(string $v): ?string
    {
        return preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', trim($v), $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    }

    private static function time(string $v): ?string
    {
        $v = strtoupper(str_replace([' ', '.'], '', trim($v)));
        if (! preg_match('/^(\d{1,2}):(\d{2})(AM|PM)?$/', $v, $m)) {
            return null;
        }
        $h = (int) $m[1];
        if (($m[3] ?? '') === 'PM' && $h < 12) {
            $h += 12;
        } elseif (($m[3] ?? '') === 'AM' && $h === 12) {
            $h = 0;
        }

        return $h < 24 ? sprintf('%02d:%02d:00', $h, $m[2]) : null;
    }
}
