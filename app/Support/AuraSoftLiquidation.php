<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * AuraSoft's "Reporte Liquidación General" (user's yes, 2026-11-24): one row per day x point of sale x cashier.
 *
 * The file: "EMPRESA :", "P. VENTA :", "SUCURSAL :" lines, then the header row (FECHA, EMPRESA, COD. EMPRESA, SUCURSAL,
 * COD. SUCURSAL, PUNTO VENTA, COD. PUNTO VENTA, USUARIO, COD. USUARIO, PASAJES, ... TOTAL EGRESOS, TOTAL (ING - EGR)) and
 * one row per cashier and day. Read BY HEADER NAME. It is by SALE date (the production report is by travel date).
 *
 * Same rule as AuraSoftImport: a row = date + branch + point of sale + cashier (codes); a later file with the same row and
 * the same figures = "same", other figures = "updated" (current), earlier ones kept. Everything recorded, one transaction.
 */
class AuraSoftLiquidation
{
    private const FIGURES = ['tickets', 'parcels', 'excess', 'collected', 'income', 'card', 'web', 'channel', 'delivery', 'cash_out', 'expenses', 'net'];

    /** The sheet's first rows say what it is: production | liquidation | detail | parcels | receivables | null. */
    public static function kindOf(array $data): ?string
    {
        foreach (array_slice($data, 0, 8) as $row) {
            $line = mb_strtoupper(implode(' ', array_map(fn ($v) => (string) $v, array_slice($row, 0, 6))));
            if (str_contains($line, 'KARDEX DE ENCOMIENDAS')) {
                return 'parcels';
            }
            if (str_contains($line, 'CREDITOS Y COBRANZAS') || str_contains($line, 'CRÉDITOS Y COBRANZAS')) {
                return 'receivables';
            }
            if (str_contains($line, 'LIQUIDACIÓN DETALLADA') || str_contains($line, 'LIQUIDACION DETALLADA')) {
                return 'detail';
            }
            if (str_contains($line, 'LIQUIDACIÓN GENERAL') || str_contains($line, 'LIQUIDACION GENERAL')) {
                return 'liquidation';
            }
            if (str_contains($line, 'PRODUCCION POR BUS') || str_contains($line, 'PRODUCCIÓN POR BUS')) {
                return 'production';
            }
        }

        return null;
    }

    /** @param array $data the sheet as rows (toArray) */
    public static function run(int $companyId, UploadedFile $file, ?int $userId, array $data): object
    {
        $parsed = self::parse($data);
        if (! $parsed['rows']) {
            throw new \RuntimeException(__('No rows were found in the "Liquidación General".'));
        }
        $sha = hash_file('sha256', $file->getRealPath());   // before putUpload moves the file
        $path = CompanyFiles::putUpload($companyId, 'transport/aurasoft', $file, true);

        return DB::transaction(function () use ($companyId, $file, $userId, $parsed, $path, $sha) {
            $now = now();
            $dates = array_column($parsed['rows'], 'sale_date');
            $importId = DB::table('transport_aurasoft_imports')->insertGetId([
                'company_id' => $companyId, 'kind' => 'liquidation', 'file' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'sha256' => $sha, 'company_text' => $parsed['company'], 'period_from' => min($dates), 'period_to' => max($dates),
                'columns' => json_encode($parsed['headers'], JSON_UNESCAPED_UNICODE), 'skipped' => json_encode($parsed['skipped'], JSON_UNESCAPED_UNICODE),
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $count = ['new' => 0, 'updated' => 0, 'same' => 0];
            $seen = [];
            $tickets = 0.0; $income = 0.0;
            foreach ($parsed['rows'] as $r) {
                if (isset($seen[$r['row_key']])) {
                    $parsed['skipped'][] = ['row' => $r['row_number'], 'reason' => 'Fila repetida en el archivo'];
                    continue;
                }
                $seen[$r['row_key']] = true;
                $current = DB::table('transport_aurasoft_liquidations')->where('company_id', $companyId)->where('row_key', $r['row_key'])->where('is_current', 1)->first();
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
                        DB::table('transport_aurasoft_liquidations')->where('id', $current->id)->update(['is_current' => 0, 'updated_at' => $now]);
                    }
                }
                $count[$action]++;
                $tickets += $r['tickets']; $income += $r['income'];
                DB::table('transport_aurasoft_liquidations')->insert(array_merge(
                    array_intersect_key($r, array_flip(array_merge(self::FIGURES, ['row_number', 'row_key', 'sale_date', 'branch', 'point', 'point_code', 'cashier', 'cashier_code']))),
                    ['company_id' => $companyId, 'import_id' => $importId, 'values' => json_encode($r['values'], JSON_UNESCAPED_UNICODE),
                        'action' => $action, 'is_current' => $action === 'same' ? 0 : 1, 'created_at' => $now, 'updated_at' => $now]
                ));
            }
            DB::table('transport_aurasoft_imports')->where('id', $importId)->update([
                'rows_total' => array_sum($count), 'rows_new' => $count['new'], 'rows_updated' => $count['updated'], 'rows_same' => $count['same'],
                'rows_skipped' => count($parsed['skipped']), 'skipped' => json_encode($parsed['skipped'], JSON_UNESCAPED_UNICODE),
                'ticket_income' => round($tickets, 2), 'production' => round($income, 2),
            ]);

            return DB::table('transport_aurasoft_imports')->where('id', $importId)->first();
        });
    }

    /** Removes a liquidation import; for each row it held as current, the latest earlier one becomes current again. */
    public static function remove(int $companyId, int $importId): void
    {
        DB::transaction(function () use ($companyId, $importId) {
            $keys = DB::table('transport_aurasoft_liquidations')->where('company_id', $companyId)->where('import_id', $importId)->where('is_current', 1)->pluck('row_key');
            DB::table('transport_aurasoft_liquidations')->where('company_id', $companyId)->where('import_id', $importId)->delete();
            foreach ($keys as $key) {
                $prev = DB::table('transport_aurasoft_liquidations')->where('company_id', $companyId)->where('row_key', $key)->where('action', '!=', 'same')->orderByDesc('id')->value('id');
                if ($prev) {
                    DB::table('transport_aurasoft_liquidations')->where('id', $prev)->update(['is_current' => 1, 'updated_at' => now()]);
                }
            }
            DB::table('transport_aurasoft_imports')->where('company_id', $companyId)->where('id', $importId)->delete();
        });
    }

    /** @return array{company: ?string, headers: array, rows: array, skipped: array} */
    public static function parse(array $data): array
    {
        $out = ['company' => null, 'headers' => [], 'rows' => [], 'skipped' => []];
        $headerAt = null;
        foreach ($data as $i => $row) {
            $first = mb_strtoupper(trim((string) ($row[0] ?? '')));
            if (str_starts_with($first, 'EMPRESA')) {
                $out['company'] = trim((string) ($row[1] ?? ''));
            } elseif ($first === 'FECHA') {
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
        $txt = fn (array $row, string $h) => isset($col[$h]) ? trim((string) ($row[$col[$h]] ?? '')) : '';
        $num = fn (array $row, string $h) => isset($col[$h]) ? round((float) str_replace(',', '', (string) ($row[$col[$h]] ?? 0)), 2) : 0.0;
        foreach (array_slice($data, $headerAt + 1, null, true) as $i => $row) {
            $d = $txt($row, 'FECHA');
            if (! preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $d, $m)) {
                if ($d !== '' && ! str_starts_with(mb_strtoupper($d), 'TOTAL')) {
                    $out['skipped'][] = ['row' => $i + 1, 'reason' => 'Fecha no válida'];
                }
                continue;
            }
            $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            $values = [];
            foreach ($headers as $c => $h) {
                $values[$h] = $row[$c] ?? null;
            }
            $codes = [$txt($row, 'COD. SUCURSAL') ?: $txt($row, 'SUCURSAL'), $txt($row, 'COD. PUNTO VENTA') ?: $txt($row, 'PUNTO VENTA'), $txt($row, 'COD. USUARIO') ?: $txt($row, 'USUARIO')];
            $out['rows'][] = [
                'row_number' => $i + 1, 'row_key' => mb_substr($date.'|'.implode('|', $codes), 0, 160), 'sale_date' => $date,
                'branch' => mb_substr($txt($row, 'SUCURSAL'), 0, 80), 'point' => mb_substr($txt($row, 'PUNTO VENTA'), 0, 80), 'point_code' => mb_substr($codes[1], 0, 20),
                'cashier' => mb_substr($txt($row, 'USUARIO'), 0, 80), 'cashier_code' => mb_substr($codes[2], 0, 20),
                'tickets' => $num($row, 'PASAJES'), 'parcels' => $num($row, 'ENCOMIENDAS'), 'excess' => $num($row, 'EXCESOS'),
                'collected' => $num($row, 'COB. DESTINO'), 'income' => $num($row, 'TOTAL INGRESOS'),
                'card' => round($num($row, 'V.TAR. PASAJES') + $num($row, 'V.TAR. ENCOMI.') + $num($row, 'V.TAR. EXCESOS'), 2),
                'web' => $num($row, 'WEB EMITIDOS'), 'channel' => $num($row, 'CANAL VENTA'), 'delivery' => $num($row, 'DEL. EMITIDOS'),
                'cash_out' => round($num($row, 'EGR. CAJA PAS.') + $num($row, 'EGR. CAJA EN.'), 2),
                'expenses' => $num($row, 'TOTAL EGRESOS'), 'net' => $num($row, 'TOTAL (ING - EGR)'), 'values' => $values,
            ];
        }
        // The same cashier twice on one day and point of sale (AuraSoft lists a second session): one row, figures added up.
        $merged = [];
        foreach ($out['rows'] as $r) {
            if (! isset($merged[$r['row_key']])) {
                $merged[$r['row_key']] = $r;
                continue;
            }
            foreach (self::FIGURES as $f) {
                $merged[$r['row_key']][$f] = round($merged[$r['row_key']][$f] + $r[$f], 2);
            }
            $merged[$r['row_key']]['values'] = ['merged_rows' => [$merged[$r['row_key']]['values'], $r['values']]];
        }
        $out['rows'] = array_values($merged);

        return $out;
    }
}
