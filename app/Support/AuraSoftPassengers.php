<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Turismo's passenger base (user's rule, 2026-11-24), table transport_passengers -- never in clients, so the customers'
 * table stays light for every other company.
 *
 *  - sync(): from AuraSoft's Liquidación Detallada (transport_aurasoft_movements, current rows): one passenger per DNI /
 *    RUC with its latest name, first / last purchase, purchases, parcels and amount (recomputed each time, so a re-upload
 *    never double counts). A passenger known only by name + phone (from a PDF) gets its DNI when a document brings the
 *    same name.
 *  - phones(): from AuraSoft's RedBus / web sales report (PDF -> records {name, phone, emitted, travel}): matched by name
 *    (name_key, 96 % of RedBus' Oct-Nov tickets matched), the latest phone kept + every phone seen; a buyer not found is
 *    added with name + phone only. online_tickets = the records given (pass the whole set, it overwrites).
 */
class AuraSoftPassengers
{
    private const MODULES = ['PAS_ING', 'VEN_ENC', 'VEN_EXE', 'COB_DES', 'ENC_PAG'];

    /** Words without accents, sorted: "José Luis, Muñoz Vásquez" -> "JOSE LUIS MUNOZ VASQUEZ" sorted. */
    public static function nameKey(string $name): string
    {
        $s = mb_strtoupper(trim($name));
        $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $t = preg_split('/[^A-Z]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        $t = array_values(array_filter($t, fn ($x) => strlen($x) > 1 && ! in_array($x, ['DE', 'DEL', 'LA', 'LOS', 'LAS', 'Y'], true)));
        sort($t);

        return mb_substr(implode(' ', $t), 0, 190);
    }

    /** Peru's phones as 51 + 9 digits: "5151941887518" (51 typed twice) -> "51941887518", "987654321" -> "51987654321". */
    public static function phone(string $raw): string
    {
        $d = preg_replace('/\D/', '', $raw);
        if (strlen($d) === 13 && str_starts_with($d, '5151')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 9 && $d[0] === '9') {
            $d = '51'.$d;
        }

        return strlen($d) >= 9 && strlen($d) <= 15 ? $d : '';
    }

    /**
     * The name of each DNI / RUC = the one its documents carry most often, the latest on a tie (2026-11-24: a cashier
     * sometimes types a company's name over another's RUC -- "TRUCKS PARTS" on El Cumbe's RUC -- so the latest or the
     * alphabetically last name is not reliable).
     *
     * @return array<string, string> doc => raw name
     */
    public static function namesByDoc(int $companyId, ?array $docs = null): array
    {
        $best = [];
        $q = DB::table('transport_aurasoft_movements')->where('company_id', $companyId)->where('is_current', 1)
            ->whereIn('module', self::MODULES)->whereNotNull('client_doc')->where('client_doc', '!=', '')->whereNotNull('client')->where('client', '!=', '')
            ->when($docs !== null, fn ($w) => $w->whereIn('client_doc', $docs ?: ['-']))
            ->groupBy('client_doc', 'client')->selectRaw('client_doc, client, COUNT(*) c, MAX(sale_date) d');
        foreach ($q->cursor() as $r) {
            $b = $best[$r->client_doc] ?? null;
            if (! $b || $r->c > $b[1] || ($r->c == $b[1] && $r->d > $b[2])) {
                $best[$r->client_doc] = [$r->client, (int) $r->c, $r->d];
            }
        }

        return array_map(fn ($b) => $b[0], $best);
    }

    /** passenger | parcel | both (2026-11-24): who travelled (tickets, online tickets) and who sent parcels. */
    public static function refreshKind(?int $companyId = null): void
    {
        DB::table('transport_passengers')->when($companyId, fn ($q) => $q->where('company_id', $companyId))->update(['kind' => DB::raw(
            "CASE WHEN parcels > 0 AND (tickets > 0 OR online_tickets > 0) THEN 'both' WHEN parcels > 0 THEN 'parcel' ELSE 'passenger' END")]);
    }

    public static function name(string $raw): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', $raw))), 0, 190);
    }

    /** @return array{new: int, updated: int} */
    public static function sync(int $companyId): array
    {
        DB::statement('SET SESSION group_concat_max_len = 4096');
        $rows = DB::table('transport_aurasoft_movements')->where('company_id', $companyId)->where('is_current', 1)
            ->whereIn('module', self::MODULES)->whereNotNull('client_doc')->where('client_doc', '!=', '')
            ->groupBy('client_doc')
            ->selectRaw("client_doc,
                SUBSTRING_INDEX(GROUP_CONCAT(client ORDER BY sale_date DESC, id DESC SEPARATOR '\x1F'), '\x1F', 1) client,
                MIN(sale_date) d1, MAX(sale_date) d2,
                SUM(status IS NULL OR status != 'ANULADO') n,
                SUM(CASE WHEN status IS NULL OR status != 'ANULADO' THEN income ELSE 0 END) amount,
                SUM((module = 'VEN_ENC' OR module = 'COB_DES' OR module = 'ENC_PAG') AND (status IS NULL OR status != 'ANULADO')) parcels,
                SUM((module = 'PAS_ING' OR module = 'VEN_EXE') AND (status IS NULL OR status != 'ANULADO')) tickets")
            ->get();
        $names = self::namesByDoc($companyId);
        $byDoc = DB::table('transport_passengers')->where('company_id', $companyId)->whereNotNull('doc')->pluck('id', 'doc');
        $nameOnly = DB::table('transport_passengers')->where('company_id', $companyId)->whereNull('doc')->pluck('id', 'name_key');
        $now = now();
        $new = 0; $updated = 0; $ins = [];
        foreach ($rows as $r) {
            $doc = preg_replace('/\D/', '', (string) $r->client_doc);
            if (strlen($doc) < 8 || strlen($doc) > 11 || preg_match('/^0+$/', $doc)) {
                continue;
            }
            $name = self::name((string) ($names[(string) $r->client_doc] ?? $r->client)) ?: $doc;
            $key = self::nameKey($name);
            $vals = ['name' => $name, 'name_key' => $key, 'is_company' => strlen($doc) === 11, 'first_purchase' => $r->d1, 'last_purchase' => $r->d2,
                'purchases' => (int) $r->n, 'parcels' => (int) $r->parcels, 'tickets' => (int) $r->tickets, 'amount' => round((float) $r->amount, 2), 'updated_at' => $now];
            $id = $byDoc[$doc] ?? null;
            if (! $id && $key !== '' && isset($nameOnly[$key])) {
                $id = $nameOnly[$key];                       // known by name + phone: it gets its DNI now
                unset($nameOnly[$key]);
                $vals['doc'] = $doc;
            }
            if ($id) {
                DB::table('transport_passengers')->where('id', $id)->update($vals);
                $updated++;
                continue;
            }
            $ins[] = $vals + ['company_id' => $companyId, 'doc' => $doc, 'phone' => null, 'phones' => null, 'online_tickets' => 0, 'created_at' => $now];
            $new++;
            if (count($ins) >= 1000) {
                DB::table('transport_passengers')->insertOrIgnore($ins);
                $ins = [];
            }
        }
        if ($ins) {
            DB::table('transport_passengers')->insertOrIgnore($ins);
        }

        self::refreshKind($companyId);

        return ['new' => $new, 'updated' => $updated];
    }

    /**
     * @param  array<int, array{name: string, phone: string, emitted?: string, travel?: string}>  $records
     * @return array{matched: int, added: int, records: int}
     */
    public static function phones(int $companyId, array $records): array
    {
        // Group the records by buyer (name key): latest phone, every phone, tickets, last emission.
        $buyers = [];
        foreach ($records as $r) {
            $key = self::nameKey((string) ($r['name'] ?? ''));
            $phone = self::phone((string) ($r['phone'] ?? ''));
            if ($key === '' || substr_count($key, ' ') < 1) {
                continue;
            }
            $date = null;
            if (preg_match('~(\d{1,2})/(\d{1,2})/(\d{4})~', (string) ($r['emitted'] ?? ''), $m)) {
                $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            $b = $buyers[$key] ?? ['name' => self::name((string) $r['name']), 'phone' => null, 'date' => null, 'phones' => [], 'n' => 0];
            $b['n']++;
            if ($phone !== '') {
                $b['phones'][$phone] = true;
                if ($b['date'] === null || ($date && $date >= $b['date'])) {
                    $b['phone'] = $phone;
                }
            }
            if ($date && ($b['date'] === null || $date > $b['date'])) {
                $b['date'] = $date;
            }
            $buyers[$key] = $b;
        }
        // The passengers by name key; when two share a name, the one who bought last.
        $byKey = [];
        foreach (DB::table('transport_passengers')->where('company_id', $companyId)->orderBy('last_purchase')->get(['id', 'name_key', 'phones']) as $p) {
            $byKey[$p->name_key] = $p;
        }
        $now = now();
        $matched = 0; $added = 0; $ins = [];
        foreach ($buyers as $key => $b) {
            $p = $byKey[$key] ?? null;
            $phones = array_keys($b['phones']);
            if ($p) {
                $all = array_values(array_unique(array_merge(json_decode((string) $p->phones, true) ?: [], $phones)));
                DB::table('transport_passengers')->where('id', $p->id)->update(array_filter([
                    'phone' => $b['phone'], 'phones' => $all ? json_encode($all) : null, 'online_tickets' => $b['n'], 'last_online' => $b['date'], 'updated_at' => $now,
                ], fn ($v) => $v !== null));
                $matched++;
                continue;
            }
            $ins[] = ['company_id' => $companyId, 'doc' => null, 'name' => $b['name'], 'name_key' => $key, 'is_company' => false, 'phone' => $b['phone'],
                'phones' => $phones ? json_encode($phones) : null, 'online_tickets' => $b['n'], 'last_online' => $b['date'],
                'purchases' => 0, 'parcels' => 0, 'amount' => 0, 'created_at' => $now, 'updated_at' => $now];
            $added++;
            if (count($ins) >= 1000) {
                DB::table('transport_passengers')->insert($ins);
                $ins = [];
            }
        }
        if ($ins) {
            DB::table('transport_passengers')->insert($ins);
        }

        self::refreshKind($companyId);

        return ['matched' => $matched, 'added' => $added, 'records' => count($records)];
    }
}
