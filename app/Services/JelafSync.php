<?php

namespace App\Services;

use App\Support\AuraSoftImport;
use App\Support\AuraSoftPassengers;
use App\Support\JelafClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Jelaf automático (2026-11-26, Turismo Cautivo; Julio's data): the reports Murano imports from Jelaf / AuraSoft, run by the
 * 22:00 scheduler and from Integración -> Jelaf automático at any moment. Every run is a transport_jelaf_runs row.
 *  - production: "Reporte producción por bus" (resumido F1, every company) of the range, imported with AuraSoftImport;
 *  - boarding:   "Control de Embarque" Resumen + Detalle of each travel day (one file per day and report), the Detalle
 *                imported into transport_boardings (a day's import replaces that day).
 * Each downloaded file is kept: KT-TOOLS/files/turismo/JELAF_INTEGRACION on a PC, storage/app/jelaf/archive on the server.
 */
class JelafSync
{
    public const REPORTS = [
        'production' => ['Producción por bus', 'Una fila por salida: bus, ruta, hora, pasajes por canal, encomiendas y exceso. Llena Producción por bus, Ventas del día, Reporte de salidas y Resumen del negocio.'],
        'boarding' => ['Control de Embarque', 'Un pasajero por fila: bus y salida, asiento, vendedor, fecha y hora de venta, boleto, monto, si embarcó y dónde. Para no embarcados, ocupación real y puntos de embarque.'],
        'passengers' => ['Pasajeros (DNI y teléfono)', 'Pasajeros Frecuentes: por cada boleto el documento, nombre, teléfono y correo de quien viaja. Llena la Base de pasajeros.'],
        'ahead' => ['Pasajes vendidos por adelantado', 'El Control de Embarque de los próximos 14 días: lo vendido hasta hoy en cada salida. Llena el tablero Programación.'],
    ];

    public static function companyId(): int
    {
        return (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
    }

    public static function archiveDir(string $sub): string
    {
        return is_dir(base_path('KT-TOOLS/files/turismo/JELAF_INTEGRACION'))
            ? base_path('KT-TOOLS/files/turismo/JELAF_INTEGRACION/'.$sub)
            : storage_path('app/jelaf/archive/'.$sub);
    }

    /** Run one report for a date range; returns the run row. Never throws: a failure is recorded on the run. */
    public static function run(int $cid, string $report, string $from, string $to, string $trigger = 'night', ?int $userId = null, ?callable $say = null): object
    {
        $say ??= fn ($m) => null;
        $id = DB::table('transport_jelaf_runs')->insertGetId(['company_id' => $cid, 'report' => $report, 'date_from' => $from, 'date_to' => $to,
            'trigger' => $trigger, 'status' => 'running', 'user_id' => $userId, 'started_at' => now()]);
        $done = function (string $status, int $files, int $rows, array $summary = [], ?string $msg = null) use ($id) {
            DB::table('transport_jelaf_runs')->where('id', $id)->update(['status' => $status, 'files' => $files, 'rows' => $rows,
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'message' => $msg, 'finished_at' => now()]);

            return DB::table('transport_jelaf_runs')->find($id);
        };
        try {
            $client = JelafClient::fromEnvFile();
            $client->login();
            if ($report === 'production') {
                $dir = self::archiveDir('01 Produccion por Bus/Nocturno');
                $file = $dir.'/ProduccionParaBus '.$from.'_'.$to.' resumido F1 (bajado '.now()->format('Y-m-d H-i').').xlsx';
                if (! $client->productionPerBus($from, $to, $file)) {
                    return $done('nodata', 0, 0, [], 'Jelaf: no hay datos en el período.');
                }
                $copy = storage_path('app/jelaf/'.basename($file));
                @mkdir(dirname($copy), 0775, true);
                copy($file, $copy);
                $imp = AuraSoftImport::run($cid, new UploadedFile($copy, basename($file), null, null, true), $userId);
                @unlink($copy);
                $say("production $from..$to: {$imp->rows_total} rows (new {$imp->rows_new}, changed {$imp->rows_updated}, same {$imp->rows_same})");

                return $done('ok', 1, (int) $imp->rows_total, ['import_id' => $imp->id, 'new' => $imp->rows_new, 'changed' => $imp->rows_updated,
                    'same' => $imp->rows_same, 'skipped' => $imp->rows_skipped]);
            }
            if ($report === 'boarding') {
                $files = $rows = 0;
                $sum = ['days' => 0, 'boarded' => 0, 'no_show' => 0, 'amount' => 0.0, 'departures' => 0];
                for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
                    $day = date('Y-m-d', $d);
                    $res = self::boardingDay($client, $cid, $day, true);
                    $files += $res['files'];
                    $rows += $res['rows'];
                    foreach (['boarded', 'no_show', 'amount', 'departures'] as $k) {
                        $sum[$k] += $res[$k];
                    }
                    $sum['days']++;
                    $say("boarding $day: {$res['rows']} tickets, {$res['no_show']} not boarded");
                    if ($d < strtotime($to)) {
                        sleep(3);
                    }
                }
                $sum['amount'] = round($sum['amount'], 2);
                // The passengers follow the re-imported days (their tickets came back without the link).
                $p = TransportPassengers::rebuild($cid);
                $sum['tickets_linked'] = $p['tickets_linked'];
                $say("passengers: {$p['tickets_linked']} of {$p['tickets']} tickets linked, online sales {$p['online_linked']} of {$p['online']}");

                return $done($rows ? 'ok' : 'nodata', $files, $rows, $sum);
            }

            // The travellers' documents (2026-11-26): Pasajeros Frecuentes of the range (DNI, phone, email per ticket), one file.
            if ($report === 'passengers') {
                $dir = self::archiveDir('05 Pasajeros Frecuentes/Nocturno');
                $file = "$dir/PasajerosFrecuentes $from"."_$to (bajado ".now()->format('Y-m-d H-i').').xlsx';
                if (! $client->frequentPassengers($from, $to, $file)) {
                    return $done('nodata', 0, 0, [], 'Jelaf: no hay datos en el período.');
                }
                $imp = TransportPassengers::importFrequentFile($cid, $file);
                $say("passengers $from..$to: {$imp['rows']} tickets with their document (new {$imp['new']})");

                return $done('ok', 1, $imp['rows'], $imp);
            }
            // Seats sold ahead (2026-11-26, the Programación board): the Detalle of the next days, what is sold so far.
            if ($report === 'ahead') {
                $dir = self::archiveDir('06 Control de Embarque/Descargado_Jelaf_ControlEmbarqueDetalle');
                $files = $rows = 0;
                $sum = ['days' => 0, 'tickets' => 0, 'departures' => 0];
                for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
                    $day = date('Y-m-d', $d);
                    $file = "$dir/ControlEmbarqueDetalle_NombrePasajero-$day.xlsx";
                    $client->boarding('detalle', $day, $file) && $files++;
                    $res = is_file($file) ? self::importBoardingFile($cid, $file, $day) : ['rows' => 0, 'departures' => 0];
                    $rows += $res['rows'];
                    $sum['tickets'] += $res['rows'];
                    $sum['departures'] += $res['departures'];
                    $sum['days']++;
                    $say("ahead $day: {$res['rows']} tickets sold so far on {$res['departures']} departures");
                    if ($d < strtotime($to)) {
                        sleep(3);
                    }
                }
                Cache::forget("transport_programming_curve_$cid");

                return $done($rows ? 'ok' : 'nodata', $files, $rows, $sum);
            }

            return $done('failed', 0, 0, [], "Unknown report $report");
        } catch (Throwable $e) {
            report($e);

            return $done('failed', 0, 0, [], mb_substr($e->getMessage(), 0, 500));
        }
    }

    /** Download (always again: a recent day changes) both Control de Embarque files of a day and import the Detalle. */
    public static function boardingDay(JelafClient $client, int $cid, string $day, bool $fresh): array
    {
        $dirR = self::archiveDir('06 Control de Embarque/Descargado_Jelaf_ControlEmbarqueResumen');
        $dirD = self::archiveDir('06 Control de Embarque/Descargado_Jelaf_ControlEmbarqueDetalle');
        $fileR = "$dirR/ControlEmbarqueResumen_PuntoEmbarque-$day.xlsx";
        $fileD = "$dirD/ControlEmbarqueDetalle_NombrePasajero-$day.xlsx";
        $files = 0;
        if ($fresh || ! is_file($fileR)) {
            $client->boarding('resumen', $day, $fileR) && $files++;
            sleep(2);
        }
        if ($fresh || ! is_file($fileD)) {
            $client->boarding('detalle', $day, $fileD) && $files++;
        }

        return ['files' => $files] + (is_file($fileD) ? self::importBoardingFile($cid, $fileD, $day)
            : ['rows' => 0, 'boarded' => 0, 'no_show' => 0, 'amount' => 0.0, 'departures' => 0]);
    }

    private static function plateKey(?string $p): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $p));
    }

    private static function dmy(?string $d): ?string
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim((string) $d), $m) ? "$m[3]-$m[2]-$m[1]" : null;
    }

    private static function hm(?string $t): ?string
    {
        $t = strtoupper(trim((string) $t));
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?$/', $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        if (($m[4] ?? '') === 'PM' && $h < 12) {
            $h += 12;
        } elseif (($m[4] ?? '') === 'AM' && $h === 12) {
            $h = 0;
        }

        return sprintf('%02d:%02d:%02d', $h, (int) $m[2], (int) ($m[3] ?? 0));
    }

    /** Import one "Control de Embarque - Detallado" file: replaces that travel day. */
    public static function importBoardingFile(int $cid, string $file, string $day): array
    {
        // Free the workbook at once: a year of files in one run ran out of memory (2026-11-26).
        $book = IOFactory::createReaderForFile($file)->setReadDataOnly(true)->load($file);
        $rows = $book->getActiveSheet()->toArray(null, false, true, false);
        $book->disconnectWorksheets();
        unset($book);
        gc_collect_cycles();
        $h = null;
        $out = [];
        $plates = DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->get(['id', 'plate_number'])
            ->mapWithKeys(fn ($v) => [self::plateKey($v->plate_number) => $v->id]);
        foreach ($rows as $r) {
            $c = array_map(fn ($x) => $x === null ? '' : trim((string) $x), $r);
            if ($h === null) {
                if (in_array('PROGRAMACION', $c, true) && in_array('BOLETO', $c, true)) {
                    $h = array_flip($c);
                }
                continue;
            }
            if (! is_numeric($c[$h['ITEM'] ?? 0] ?? '') || ($c[$h['BOLETO']] ?? '') === '') {
                continue;
            }
            $g = fn ($k) => $c[$h[$k] ?? -1] ?? '';
            $sold = self::dmy($g('FECHA VTA'));
            $soldT = self::hm($g('HORA VTA'));
            $out[] = [
                'company_id' => $cid, 'travel_date' => self::dmy($g('FECHA')) ?: $day, 'programacion' => (int) $g('PROGRAMACION') ?: null,
                'departure_time' => self::hm($g('HORA')), 'origin' => mb_substr($g('ORIGEN'), 0, 40), 'destination' => mb_substr($g('DESTINO'), 0, 40),
                'plate' => mb_substr($g('PLACA'), 0, 20), 'vehicle_id' => $plates[self::plateKey($g('PLACA'))] ?? null, 'trip_id' => null,
                'seat' => mb_substr($g('ASIENTO'), 0, 10), 'passenger' => mb_substr($g('PASAJERO'), 0, 150),
                'name_key' => AuraSoftPassengers::nameKey($g('PASAJERO')), 'seller' => mb_substr($g('USUARIO'), 0, 80),
                'pax_origin' => mb_substr($g('ORIG.PASAJ'), 0, 40), 'pax_destination' => mb_substr($g('DEST.PASAJ'), 0, 40),
                'sold_at' => $sold ? $sold.' '.($soldT ?: '00:00:00') : null, 'ticket' => mb_substr($g('BOLETO'), 0, 30),
                'amount' => (float) str_replace(',', '', $g('MONTO')), 'boarded' => strtoupper($g('EMBARCO')) !== 'NO',
                'manifest' => mb_substr($g('MANIFIESTO'), 0, 30), 'boarding_point' => mb_substr($g('EMBARQUE'), 0, 40),
                'alighting_point' => mb_substr($g('DESEMBARQUE'), 0, 40), 'created_at' => now(),
            ];
        }
        // The Murano departure: same bus, within 90 minutes of the programmed time.
        $trips = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('departure_at', $day)->get(['id', 'vehicle_id', 'departure_at']);
        foreach ($out as &$o) {
            if ($o['vehicle_id'] && $o['departure_time']) {
                $at = strtotime($day.' '.$o['departure_time']);
                $best = $trips->where('vehicle_id', $o['vehicle_id'])->sortBy(fn ($t) => abs(strtotime($t->departure_at) - $at))->first();
                if ($best && abs(strtotime($best->departure_at) - $at) <= 5400) {
                    $o['trip_id'] = $best->id;
                }
            }
        }
        unset($o);
        DB::transaction(function () use ($cid, $day, $out) {
            DB::table('transport_boardings')->where('company_id', $cid)->where('travel_date', $day)->delete();
            foreach (array_chunk($out, 500) as $chunk) {
                DB::table('transport_boardings')->insert($chunk);
            }
        });
        $c = collect($out);

        return ['rows' => $c->count(), 'boarded' => $c->where('boarded', true)->count(), 'no_show' => $c->where('boarded', false)->count(),
            'amount' => round($c->sum('amount'), 2), 'departures' => $c->pluck('programacion')->unique()->count()];
    }
}
