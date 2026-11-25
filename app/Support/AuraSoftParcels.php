<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * AuraSoft's "Kardex de encomiendas por entregar" (user's yes, 2026-11-24): the parcels not delivered yet, one file per
 * branch ("SUCURSAL: LIMA"). Lines: company (+ the report's date), title, "DEL dd/mm/yyyy AL dd/mm/yyyy", "SUCURSAL: X",
 * "ORIGEN: X", the header (EMISIÓN, TIPO, DOCUMENTO, CONSIGNADO, DETALLE, IMPORTE, C.P, REMITENTE, PUNTO VENTA, ESTADO),
 * then a group line (an agency name alone) before its parcels and "TOTAL POR PAGAR" at the end. EMISIÓN is an Excel
 * date number. It is a snapshot: the latest file of a branch is the current list, earlier ones are kept (not current).
 */
class AuraSoftParcels
{
    public static function run(int $companyId, UploadedFile $file, ?int $userId): object
    {
        $parsed = self::parse($file->getRealPath());
        if (! $parsed['rows'] && ! $parsed['branch']) {
            throw new \RuntimeException(__('No parcels were found in the "Kardex de encomiendas por entregar".'));
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $path = CompanyFiles::putUpload($companyId, 'transport/aurasoft', $file, true);

        return DB::transaction(function () use ($companyId, $file, $userId, $parsed, $path, $sha) {
            $now = now();
            $importId = DB::table('transport_aurasoft_imports')->insertGetId([
                'company_id' => $companyId, 'kind' => 'parcels', 'file' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'sha256' => $sha, 'company_text' => $parsed['company'], 'period_from' => $parsed['from'], 'period_to' => $parsed['to'],
                'columns' => json_encode($parsed['headers'], JSON_UNESCAPED_UNICODE), 'skipped' => json_encode($parsed['skipped'], JSON_UNESCAPED_UNICODE),
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            // The branch's earlier list stops being the current one.
            $before = DB::table('transport_aurasoft_parcels')->where('company_id', $companyId)->where('branch', $parsed['branch'])->where('is_current', 1)
                ->pluck('document')->flip();
            DB::table('transport_aurasoft_parcels')->where('company_id', $companyId)->where('branch', $parsed['branch'])->where('is_current', 1)
                ->update(['is_current' => 0, 'updated_at' => $now]);
            $new = 0; $amount = 0.0;
            foreach (array_chunk($parsed['rows'], 500) as $chunk) {
                $ins = [];
                foreach ($chunk as $r) {
                    $new += isset($before[$r['document']]) ? 0 : 1;
                    $amount += $r['amount'];
                    $ins[] = $r + ['company_id' => $companyId, 'import_id' => $importId, 'branch' => $parsed['branch'], 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('transport_aurasoft_parcels')->insert($ins);
            }
            $n = count($parsed['rows']);
            DB::table('transport_aurasoft_imports')->where('id', $importId)->update([
                'rows_total' => $n, 'rows_new' => $new, 'rows_updated' => 0, 'rows_same' => $n - $new, 'rows_skipped' => count($parsed['skipped']),
                'production' => round($amount, 2),
            ]);

            return DB::table('transport_aurasoft_imports')->where('id', $importId)->first();
        });
    }

    public static function remove(int $companyId, int $importId): void
    {
        DB::transaction(function () use ($companyId, $importId) {
            $branch = DB::table('transport_aurasoft_parcels')->where('company_id', $companyId)->where('import_id', $importId)->value('branch');
            $wasCurrent = DB::table('transport_aurasoft_parcels')->where('company_id', $companyId)->where('import_id', $importId)->where('is_current', 1)->exists();
            DB::table('transport_aurasoft_parcels')->where('company_id', $companyId)->where('import_id', $importId)->delete();
            DB::table('transport_aurasoft_imports')->where('company_id', $companyId)->where('id', $importId)->delete();
            if ($wasCurrent) {
                $prev = DB::table('transport_aurasoft_parcels')->where('company_id', $companyId)->where('branch', $branch)->max('import_id');
                if ($prev) {
                    DB::table('transport_aurasoft_parcels')->where('company_id', $companyId)->where('import_id', $prev)->update(['is_current' => 1, 'updated_at' => now()]);
                }
            }
        });
    }

    /** @return array{company: ?string, branch: ?string, headers: array, rows: array, skipped: array, from: ?string, to: ?string} */
    public static function parse(string $path): array
    {
        $out = ['company' => null, 'branch' => null, 'headers' => [], 'rows' => [], 'skipped' => [], 'from' => null, 'to' => null];
        $col = null; $group = null;
        foreach (XlsxRows::read($path) as $n => $row) {
            $first = trim((string) ($row[0] ?? ''));
            $up = mb_strtoupper($first);
            if ($col === null) {
                if ($n === 1) {
                    $out['company'] = $first;
                } elseif (preg_match('~DEL\s+(\d{1,2})/(\d{1,2})/(\d{4})\s+AL\s+(\d{1,2})/(\d{1,2})/(\d{4})~u', $up, $m)) {
                    $out['from'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    $out['to'] = sprintf('%04d-%02d-%02d', $m[6], $m[5], $m[4]);
                } elseif (str_starts_with($up, 'SUCURSAL')) {
                    $out['branch'] = mb_substr(trim(preg_replace('~^SUCURSAL\s*:?~u', '', $up)), 0, 80) ?: null;
                } elseif (str_starts_with($up, 'EMISI')) {
                    $col = [];
                    foreach ($row as $c => $h) {
                        $h = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $h)));
                        if ($h !== '') {
                            $col[str_replace('Ó', 'O', $h)] = $c;
                            $out['headers'][] = $h;
                        }
                    }
                }
                continue;
            }
            $txt = fn (string $h, int $len) => isset($col[$h]) ? mb_substr(trim((string) ($row[$col[$h]] ?? '')), 0, $len) : '';
            $doc = $txt('DOCUMENTO', 30);
            if ($doc === '') {
                $joined = mb_strtoupper(implode(' ', array_map(fn ($v) => (string) $v, $row)));
                if ($first !== '' && ! str_contains($joined, 'TOTAL')) {
                    $group = mb_substr($first, 0, 80);   // an agency's group line
                }
                continue;
            }
            $em = $row[$col['EMISION'] ?? 0] ?? null;
            $emitted = null;
            if (is_numeric($em) && $em > 30000) {
                $emitted = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $em)->format('Y-m-d');
            } elseif (is_string($em) && preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})~', $em, $m)) {
                $emitted = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            $out['rows'][] = [
                'row_number' => $n, 'origin' => $group, 'emitted_on' => $emitted, 'doc_type' => $txt('TIPO', 4), 'document' => $doc,
                'consignee' => $txt('CONSIGNADO', 160) ?: null, 'detail' => $txt('DETALLE', 250) ?: null,
                'amount' => round((float) str_replace(',', '', (string) ($row[$col['IMPORTE'] ?? -1] ?? 0)), 2),
                'pay' => $txt('C.P', 6) ?: null, 'sender' => $txt('REMITENTE', 160) ?: null, 'point' => $txt('PUNTO VENTA', 80) ?: null,
                'status' => mb_strtoupper($txt('ESTADO', 40)) ?: null,
            ];
        }

        return $out;
    }
}
