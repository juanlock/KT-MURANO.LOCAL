<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * AuraSoft (ex Jelaf) "Reporte producción por bus" import (Integración -> Producción AuraSoft, 2026-11-23).
 *
 * The file: a title block (FECHA, hour, "EMPRESA: ...", "REPORTE PRODUCCION POR BUS", "DEL: d/m/Y AL: d/m/Y"), a header row
 * starting with PLACA, one row per departure (PLACA, FECHA, HORARIO "08:00PM", RUTA "LIMA / CHICLAYO", CNT ASI., the
 * sellers' seat counts, the payment columns...) and a "TOTAL --->" row. Columns change with the period (only the payment
 * methods used appear) and some trailing ones come without a header, so everything is read BY HEADER NAME.
 *
 * A departure = plate + date + time + route. Files are downloaded by date range, so they overlap: a departure already
 * imported is compared with its current row -- same figures = "same", different = "updated" (the new row becomes current,
 * the earlier one stays as history). Everything in one transaction.
 */
class AuraSoftImport
{
    /** The sellers' seat-count columns (AURA SOFT = sold through AuraSoft itself; the rest sell on it, like PeruBus). */
    public const CHANNELS = ['AURA SOFT', 'CARRITOC', 'KIOSCO', 'KUPOS', 'NO REGISTRADO', 'REDBUS', 'PERUBUS'];

    /** Figures compared to decide "same" / "updated". */
    private const FIGURES = ['seats', 'ticket_income', 'parcels', 'parcel_income', 'excess', 'excess_income', 'production'];

    /** Reads, stores the file privately and records its rows. Returns the import row. */
    public static function run(int $companyId, UploadedFile $file, ?int $userId): object
    {
        // One upload button for AuraSoft's files (2026-11-24): the file's title says which report it is.
        $data = IOFactory::load($file->getRealPath())->getSheet(0)->toArray(null, true, false, false);
        $kind = AuraSoftLiquidation::kindOf($data);
        if ($kind === 'liquidation') {
            return AuraSoftLiquidation::run($companyId, $file, $userId, $data);
        }
        if ($kind === 'detail') {
            throw new \RuntimeException(__('The "Liquidación Detallada" is not imported yet: upload the "Liquidación General" or the "Reporte producción por bus".'));
        }
        unset($data);
        $parsed = self::parse($file->getRealPath());
        if (! $parsed['rows']) {
            throw new \RuntimeException(__('No departures were found in the file. Is it the AuraSoft "Reporte producción por bus"?'));
        }
        $sha = hash_file('sha256', $file->getRealPath());   // before putUpload moves the file
        $path = CompanyFiles::putUpload($companyId, 'transport/aurasoft', $file, true);
        $plates = DB::table('vehicles')->where('company_id', $companyId)->whereNull('deleted_at')->whereNotNull('plate_number')->where('plate_number', '!=', '')
            ->get(['id', 'plate_number'])->mapWithKeys(fn ($v) => [self::plateKey($v->plate_number) => $v->id]);

        $import = DB::transaction(function () use ($companyId, $file, $userId, $parsed, $path, $sha, $plates) {
            $now = now();
            $importId = DB::table('transport_aurasoft_imports')->insertGetId([
                'company_id' => $companyId, 'file' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 250), 'sha256' => $sha,
                'company_text' => $parsed['company'], 'period_from' => $parsed['from'], 'period_to' => $parsed['to'],
                'columns' => json_encode($parsed['headers'], JSON_UNESCAPED_UNICODE), 'skipped' => json_encode($parsed['skipped'], JSON_UNESCAPED_UNICODE),
                'rows_skipped' => count($parsed['skipped']), 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $count = ['new' => 0, 'updated' => 0, 'same' => 0];
            $seen = [];
            $seats = 0; $income = 0.0; $production = 0.0;
            foreach ($parsed['rows'] as $r) {
                if (isset($seen[$r['departure_key']])) {
                    // The same departure twice in one file: keep the first, report the repeat.
                    $parsed['skipped'][] = ['row' => $r['row_number'], 'reason' => 'Salida repetida en el archivo'];
                    continue;
                }
                $seen[$r['departure_key']] = true;
                $current = DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('departure_key', $r['departure_key'])->where('is_current', 1)->first();
                $action = 'new';
                if ($current) {
                    $same = true;
                    foreach (self::FIGURES as $f) {
                        if (abs((float) $current->{$f} - (float) $r[$f]) > 0.005) {
                            $same = false;
                            break;
                        }
                    }
                    $action = $same ? 'same' : 'updated';
                    if (! $same) {
                        DB::table('transport_aurasoft_rows')->where('id', $current->id)->update(['is_current' => 0, 'updated_at' => $now]);
                    }
                }
                $count[$action]++;
                $seats += $r['seats']; $income += $r['ticket_income']; $production += $r['production'];
                DB::table('transport_aurasoft_rows')->insert([
                    'company_id' => $companyId, 'import_id' => $importId, 'row_number' => $r['row_number'], 'departure_key' => $r['departure_key'],
                    'plate' => $r['plate'], 'trip_date' => $r['trip_date'], 'departure_time' => $r['departure_time'], 'route' => $r['route'],
                    'origin' => $r['origin'], 'destination' => $r['destination'], 'seats' => $r['seats'],
                    'channels' => json_encode($r['channels'], JSON_UNESCAPED_UNICODE), 'ticket_income' => $r['ticket_income'],
                    'parcels' => $r['parcels'], 'parcel_income' => $r['parcel_income'], 'excess' => $r['excess'], 'excess_income' => $r['excess_income'],
                    'production' => $r['production'], 'values' => json_encode($r['values'], JSON_UNESCAPED_UNICODE),
                    'action' => $action, 'is_current' => $action === 'same' ? 0 : 1,
                    'vehicle_id' => $plates[self::plateKey($r['plate'])] ?? null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('transport_aurasoft_imports')->where('id', $importId)->update([
                'rows_total' => array_sum($count), 'rows_new' => $count['new'], 'rows_updated' => $count['updated'], 'rows_same' => $count['same'],
                'rows_skipped' => count($parsed['skipped']), 'skipped' => json_encode($parsed['skipped'], JSON_UNESCAPED_UNICODE),
                'seats' => $seats, 'ticket_income' => round($income, 2), 'production' => round($production, 2),
            ]);

            // Its departures reach the boards as Murano departures (2026-11-23), in the same transaction.
            $keys = DB::table('transport_aurasoft_rows')->where('import_id', $importId)->pluck('departure_key')->unique()->values()->all();
            $sync = AuraSoftTrips::sync($companyId, $keys);
            $imp = DB::table('transport_aurasoft_imports')->where('id', $importId)->first();
            $imp->trips = $sync;

            return $imp;
        });

        return $import;
    }

    /**
     * Deletes an import: its rows go, and for each departure it held as current, the latest earlier row becomes current
     * again. The file stays on disk (nothing uploaded is ever lost).
     */
    public static function remove(int $companyId, int $importId): void
    {
        if (DB::table('transport_aurasoft_imports')->where('id', $importId)->value('kind') === 'liquidation') {
            AuraSoftLiquidation::remove($companyId, $importId);

            return;
        }
        DB::transaction(function () use ($companyId, $importId) {
            $keys = DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('import_id', $importId)->where('is_current', 1)->pluck('departure_key');
            // The Murano departures they made, remembered on the earlier rows before these go.
            foreach (DB::table('transport_aurasoft_rows')->where('import_id', $importId)->whereNotNull('trip_id')->get(['departure_key', 'trip_id']) as $r) {
                DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('departure_key', $r->departure_key)->where('import_id', '!=', $importId)
                    ->whereNull('trip_id')->update(['trip_id' => $r->trip_id]);
            }
            $orphan = DB::table('transport_aurasoft_rows')->where('import_id', $importId)->whereNotNull('trip_id')->pluck('trip_id', 'departure_key');
            DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('import_id', $importId)->delete();
            foreach ($keys as $key) {
                $prev = DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('departure_key', $key)->where('action', '!=', 'same')
                    ->orderByDesc('id')->value('id');
                if ($prev) {
                    DB::table('transport_aurasoft_rows')->where('id', $prev)->update(['is_current' => 1, 'updated_at' => now()]);
                }
            }
            DB::table('transport_aurasoft_imports')->where('company_id', $companyId)->where('id', $importId)->delete();
            // The departures back to the earlier file's figures; one no file holds any more goes (AuraSoft-made only).
            AuraSoftTrips::sync($companyId, $keys->all());
            foreach ($orphan as $key => $tripId) {
                $stillHeld = DB::table('transport_aurasoft_rows')->where('company_id', $companyId)->where('departure_key', $key)->exists();
                $own = DB::table('transport_tickets')->where('trip_id', $tripId)->exists() || DB::table('transport_parcels')->where('trip_id', $tripId)->exists();
                if (! $stillHeld && ! $own) {
                    DB::table('transport_trips')->where('id', $tripId)->where('source', 'aurasoft')->where('notes', 'AuraSoft')->delete();
                }
            }
        });
    }

    /** @return array{company: ?string, from: ?string, to: ?string, headers: array, rows: array, skipped: array} */
    public static function parse(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $data = $sheet->toArray(null, true, false, false);
        $out = ['company' => null, 'from' => null, 'to' => null, 'headers' => [], 'rows' => [], 'skipped' => []];
        $headerAt = null;
        foreach ($data as $i => $row) {
            $first = trim((string) ($row[0] ?? ''));
            if (stripos($first, 'EMPRESA:') === 0) {
                $out['company'] = trim(substr($first, 8));
            } elseif (preg_match('~DEL:\s*(\d{1,2}/\d{1,2}/\d{4})\s*AL:\s*(\d{1,2}/\d{1,2}/\d{4})~i', $first, $m)) {
                $out['from'] = self::date($m[1]);
                $out['to'] = self::date($m[2]);
            } elseif (mb_strtoupper($first) === 'PLACA') {
                $headerAt = $i;
                break;
            }
        }
        if ($headerAt === null) {
            return $out;
        }
        $headers = [];
        foreach ($data[$headerAt] as $c => $h) {
            $h = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $h)));
            if ($h !== '') {
                $headers[$c] = $h;
            }
        }
        $out['headers'] = array_values($headers);
        $col = array_flip($headers);
        $num = fn (array $row, string $h) => isset($col[$h]) ? round((float) str_replace(',', '', (string) ($row[$col[$h]] ?? 0)), 2) : 0.0;
        foreach (array_slice($data, $headerAt + 1, null, true) as $i => $row) {
            $plate = mb_strtoupper(trim((string) ($row[$col['PLACA']] ?? '')));
            if ($plate === '' || str_starts_with($plate, 'TOTAL')) {
                continue;
            }
            $excelRow = $i + 1;
            $date = self::date((string) ($row[$col['FECHA'] ?? -1] ?? ''));
            $time = self::time((string) ($row[$col['HORARIO'] ?? -1] ?? ''));
            $route = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($row[$col['RUTA'] ?? -1] ?? ''))));
            if (! $date || ! $time || $route === '') {
                $out['skipped'][] = ['row' => $excelRow, 'reason' => 'Sin fecha, hora o ruta válidas'];
                continue;
            }
            [$origin, $dest] = array_pad(array_map('trim', explode('/', $route, 2)), 2, null);
            $values = [];
            foreach ($headers as $c => $h) {
                $values[$h] = $row[$c] ?? null;
            }
            $channels = [];
            foreach ($headers as $h) {
                if (in_array($h, self::CHANNELS, true)) {
                    $channels[$h] = (int) $num($row, $h);
                }
            }
            $out['rows'][] = [
                'row_number' => $excelRow, 'plate' => $plate, 'trip_date' => $date, 'departure_time' => $time, 'route' => $route,
                'origin' => $origin, 'destination' => $dest,
                'departure_key' => mb_substr(self::plateKey($plate).'|'.$date.'|'.substr($time, 0, 5).'|'.$route, 0, 80),
                'seats' => (int) $num($row, 'CNT ASI.'), 'channels' => $channels, 'ticket_income' => $num($row, 'TOTAL PAX'),
                'parcels' => (int) $num($row, 'CNT ENCO'), 'parcel_income' => $num($row, 'TOTAL ENCO'),
                'excess' => (int) $num($row, 'CNT DE DOC.EXCESOS'), 'excess_income' => $num($row, 'TOTAL EXC'),
                'production' => $num($row, 'TOTAL PRODUCCIÓN'), 'values' => $values,
            ];
        }

        return $out;
    }

    /** "BXA-388" and "BXA388" are the same plate. */
    public static function plateKey(string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($plate));
    }

    private static function date(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (is_numeric($v) && (float) $v > 30000) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v))->toDateString();
        }

        return null;
    }

    /** "08:00PM", "8:00 PM", "20:00" -> "20:00:00". */
    private static function time(string $v): ?string
    {
        $v = strtoupper(str_replace(' ', '', trim($v)));
        if (preg_match('/^(\d{1,2}):(\d{2})(AM|PM)?$/', $v, $m)) {
            $h = (int) $m[1];
            if (($m[3] ?? '') === 'PM' && $h < 12) {
                $h += 12;
            } elseif (($m[3] ?? '') === 'AM' && $h === 12) {
                $h = 0;
            }

            return $h < 24 && (int) $m[2] < 60 ? sprintf('%02d:%02d:00', $h, $m[2]) : null;
        }

        return null;
    }
}
