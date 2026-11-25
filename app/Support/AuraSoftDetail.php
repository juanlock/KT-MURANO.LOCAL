<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * AuraSoft's "Reporte Liquidación Detallada" (user's yes, 2026-11-24): one row per document / cash movement.
 *
 * Header row: FECHA, EMPRESA, SUCURSAL, PUNTO VENTA, USUARIO, MODULO, TIPO, SERIE, NUMERO, DOC. CLIENTE, CLIENTE, BANCO,
 * TIPO T, NUMERO T, MONEDA, INGRESO, EGRESO, TOTAL, ESTADO -- read by header name, streamed (XlsxRows: ~82,000 rows for
 * two months). A row = date + point + cashier + module + type + series + number (the same identity twice in one file =
 * #2, #3 in file order). Rule as the other AuraSoft files: a row held with the same total / status / customer = "same"
 * (not stored again), otherwise "updated" (the earlier version kept, no longer current) or "new". One transaction.
 */
class AuraSoftDetail
{
    public static function run(int $companyId, UploadedFile $file, ?int $userId): object
    {
        @ini_set('memory_limit', '1024M');   // two months = ~82,000 rows held in memory while compared
        @set_time_limit(600);
        $parsed = self::parse($file->getRealPath());
        if (! $parsed['rows']) {
            throw new \RuntimeException(__('No rows were found in the "Liquidación Detallada".'));
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $path = CompanyFiles::putUpload($companyId, 'transport/aurasoft', $file, true);

        return DB::transaction(function () use ($companyId, $file, $userId, $parsed, $path, $sha) {
            $now = now();
            $importId = DB::table('transport_aurasoft_imports')->insertGetId([
                'company_id' => $companyId, 'kind' => 'detail', 'file' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'sha256' => $sha, 'company_text' => $parsed['company'], 'period_from' => $parsed['from'], 'period_to' => $parsed['to'],
                'columns' => json_encode($parsed['headers'], JSON_UNESCAPED_UNICODE), 'skipped' => json_encode($parsed['skipped'], JSON_UNESCAPED_UNICODE),
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            // What is held now for the period, in memory (one query instead of one per row).
            $held = [];
            DB::table('transport_aurasoft_movements')->where('company_id', $companyId)->where('is_current', 1)
                ->whereBetween('sale_date', [$parsed['from'], $parsed['to']])->orderBy('id')
                ->select(['id', 'row_key', 'total', 'status', 'client_doc'])->chunk(5000, function ($chunk) use (&$held) {
                    foreach ($chunk as $h) {
                        $held[$h->row_key] = $h;
                    }
                });
            $count = ['new' => 0, 'updated' => 0, 'same' => 0];
            $old = []; $batch = []; $tickets = 0.0; $income = 0.0;
            foreach ($parsed['rows'] as $r) {
                $h = $held[$r['row_key']] ?? null;
                $action = 'new';
                if ($h) {
                    $same = abs((float) $h->total - $r['total']) < 0.005 && (string) $h->status === (string) $r['status'] && (string) $h->client_doc === (string) $r['client_doc'];
                    $action = $same ? 'same' : 'updated';
                    if (! $same) {
                        $old[] = $h->id;
                    }
                }
                $count[$action]++;
                if ($r['module'] === 'PAS_ING' && $r['status'] !== 'ANULADO') {
                    $tickets += $r['total'];
                }
                if ($r['status'] !== 'ANULADO') {
                    $income += $r['income'];
                }
                if ($action === 'same') {
                    continue;
                }
                $batch[] = $r + ['company_id' => $companyId, 'import_id' => $importId, 'action' => $action, 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now];
                if (count($batch) >= 1000) {
                    DB::table('transport_aurasoft_movements')->insert($batch);
                    $batch = [];
                }
            }
            foreach (array_chunk($old, 1000) as $ids) {
                DB::table('transport_aurasoft_movements')->whereIn('id', $ids)->update(['is_current' => 0, 'updated_at' => $now]);
            }
            if ($batch) {
                DB::table('transport_aurasoft_movements')->insert($batch);
            }
            DB::table('transport_aurasoft_imports')->where('id', $importId)->update([
                'rows_total' => array_sum($count), 'rows_new' => $count['new'], 'rows_updated' => $count['updated'], 'rows_same' => $count['same'],
                'rows_skipped' => count($parsed['skipped']), 'ticket_income' => round($tickets, 2), 'production' => round($income, 2),
            ]);
            // Its customers go to the passenger base (2026-11-24: transport_passengers, never clients), same transaction.
            $passengers = AuraSoftPassengers::sync($companyId);
            $imp = DB::table('transport_aurasoft_imports')->where('id', $importId)->first();
            $imp->passengers_new = $passengers['new'];

            return $imp;
        });
    }

    /** Removes a detail import; each row it changed gets its earlier version back as current. */
    public static function remove(int $companyId, int $importId): void
    {
        DB::transaction(function () use ($companyId, $importId) {
            $keys = DB::table('transport_aurasoft_movements')->where('company_id', $companyId)->where('import_id', $importId)->where('action', 'updated')->pluck('row_key');
            DB::table('transport_aurasoft_movements')->where('company_id', $companyId)->where('import_id', $importId)->delete();
            foreach ($keys->chunk(500) as $chunk) {
                $prev = DB::table('transport_aurasoft_movements')->where('company_id', $companyId)->whereIn('row_key', $chunk->all())
                    ->selectRaw('MAX(id) id')->groupBy('row_key')->pluck('id');
                DB::table('transport_aurasoft_movements')->whereIn('id', $prev)->update(['is_current' => 1, 'updated_at' => now()]);
            }
            DB::table('transport_aurasoft_imports')->where('company_id', $companyId)->where('id', $importId)->delete();
        });
    }

    /** @return array{company: ?string, headers: array, rows: array, skipped: array, from: ?string, to: ?string} */
    public static function parse(string $path): array
    {
        $out = ['company' => null, 'headers' => [], 'rows' => [], 'skipped' => [], 'from' => null, 'to' => null];
        $col = null; $seen = [];
        foreach (XlsxRows::read($path) as $n => $row) {
            if ($col === null) {
                $first = mb_strtoupper(trim((string) ($row[0] ?? '')));
                if (str_starts_with($first, 'EMPRESA')) {
                    $out['company'] = trim((string) ($row[1] ?? ''));
                } elseif ($first === 'FECHA') {
                    $col = [];
                    foreach ($row as $c => $h) {
                        $h = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $h)));
                        if ($h !== '') {
                            $col[$h] = $c;
                            $out['headers'][] = $h;
                        }
                    }
                }
                continue;
            }
            $txt = fn (string $h, int $len) => isset($col[$h]) ? mb_substr(trim((string) ($row[$col[$h]] ?? '')), 0, $len) : '';
            $num = fn (string $h) => isset($col[$h]) ? round((float) str_replace(',', '', (string) ($row[$col[$h]] ?? 0)), 2) : 0.0;
            $d = $txt('FECHA', 20);
            if (! preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $d, $m)) {
                if ($d !== '' && ! str_starts_with(mb_strtoupper($d), 'TOTAL')) {
                    $out['skipped'][] = ['row' => $n, 'reason' => 'Fecha no válida'];
                }
                continue;
            }
            $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            $module = mb_strtoupper($txt('MODULO', 12));
            if ($module === '') {
                $out['skipped'][] = ['row' => $n, 'reason' => 'Sin módulo'];
                continue;
            }
            $r = [
                'row_number' => $n, 'sale_date' => $date, 'branch' => $txt('SUCURSAL', 80), 'point' => $txt('PUNTO VENTA', 80), 'cashier' => $txt('USUARIO', 80),
                'module' => $module, 'doc_type' => $txt('TIPO', 4), 'series' => $txt('SERIE', 10), 'number' => $txt('NUMERO', 20),
                'client_doc' => $txt('DOC. CLIENTE', 20) ?: null, 'client' => $txt('CLIENTE', 160) ?: null, 'bank' => $txt('BANCO', 40) ?: null,
                'card_type' => $txt('TIPO T', 40) ?: null, 'card_number' => $txt('NUMERO T', 40) ?: null,
                'income' => $num('INGRESO'), 'outflow' => $num('EGRESO'), 'total' => $num('TOTAL'), 'status' => mb_strtoupper($txt('ESTADO', 12)) ?: null,
            ];
            $id = implode('|', [$date, $r['point'], $r['cashier'], $module, $r['doc_type'], $r['series'], $r['number']]);
            $seen[$id] = ($seen[$id] ?? 0) + 1;
            $r['row_key'] = sha1($id.($seen[$id] > 1 ? '#'.$seen[$id] : ''));
            $out['rows'][] = $r;
            $out['from'] = $out['from'] === null || $date < $out['from'] ? $date : $out['from'];
            $out['to'] = $out['to'] === null || $date > $out['to'] ? $date : $out['to'];
        }

        return $out;
    }
}
