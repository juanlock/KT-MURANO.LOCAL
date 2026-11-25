<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The customers of AuraSoft's "Liquidación Detallada" as Murano customers of type "Pasajero" (user's rule, 2026-11-24).
 *
 * Identity = DNI / RUC (clients.tax_number) within the company -- AuraSoft brings no email, so this is the exception to
 * "a customer = email + company", approved by the user for passengers. A DNI / RUC the company already has as a customer
 * (any type) is never duplicated nor changed to Pasajero; a passenger's name is refreshed from the latest document.
 * A passenger never gets a login or portal (LinkedUserService::ensureForClient refuses it). No email, no phone.
 */
class AuraSoftClients
{
    /** @return array{new: int, renamed: int} */
    public static function sync(int $companyId, ?int $importId = null): array
    {
        $q = DB::table('transport_aurasoft_movements')->where('company_id', $companyId)
            ->whereNotNull('client_doc')->where('client_doc', '!=', '')
            ->whereIn('module', ['PAS_ING', 'VEN_ENC', 'VEN_EXE', 'COB_DES', 'ENC_PAG'])
            ->when($importId, fn ($w) => $w->where('import_id', $importId), fn ($w) => $w->where('is_current', 1));
        // The latest name each document carried.
        $docs = [];
        foreach ($q->select(['client_doc', 'client'])->orderBy('sale_date')->orderBy('id')->cursor() as $r) {
            $doc = preg_replace('/\D/', '', (string) $r->client_doc);
            if (strlen($doc) < 8 || strlen($doc) > 11 || preg_match('/^0+$/', $doc)) {
                continue;
            }
            $name = self::name((string) $r->client);
            if ($name !== '' || ! isset($docs[$doc])) {
                $docs[$doc] = $name;
            }
        }
        if (! $docs) {
            return ['new' => 0, 'renamed' => 0];
        }
        $held = [];
        foreach (array_chunk(array_keys($docs), 2000) as $chunk) {
            foreach (DB::table('clients')->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('tax_number', $chunk)
                ->get(['id', 'tax_number', 'name', 'client_type']) as $c) {
                $held[$c->tax_number] = $c;
            }
        }
        $now = now();
        $code = (int) DB::table('clients')->max('code');
        $new = 0; $renamed = 0; $batch = [];
        foreach ($docs as $doc => $name) {
            $c = $held[$doc] ?? null;
            if ($c) {
                if ($c->client_type === 'passenger' && $name !== '' && $c->name !== $name) {
                    DB::table('clients')->where('id', $c->id)->update(['name' => $name, 'updated_at' => $now]);
                    $renamed++;
                }
                continue;
            }
            $batch[] = [
                'company_id' => $companyId, 'client_type' => 'passenger', 'name' => $name !== '' ? $name : $doc, 'code' => ++$code,
                'tax_number' => $doc, 'country' => 'Peru',
                'notes' => strlen($doc) === 11 ? 'AuraSoft · empresa (RUC)' : 'AuraSoft · persona (DNI)',
                'created_at' => $now, 'updated_at' => $now,
            ];
            $new++;
            if (count($batch) >= 1000) {
                DB::table('clients')->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('clients')->insert($batch);
        }

        return ['new' => $new, 'renamed' => $renamed];
    }

    /** "PABLO BASILIO,PALOMINO,QUISPE" -> "PABLO BASILIO PALOMINO QUISPE"; "ALISPED S.A.C.,," -> "ALISPED S.A.C." */
    public static function name(string $raw): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', $raw))), 0, 190);
    }
}
