<?php

namespace App\Services;

use App\Support\AuraSoftPassengers;
use App\Support\PdfText;
use Illuminate\Support\Facades\DB;

/**
 * Turismo's passenger base (transport_passengers, AuraSoftPassengers) crossed with Jelaf's tickets (2026-11-26).
 *
 *  - importOnlinePdf(): Jelaf's "Reporte de ventas web" PDF (generar-reporte-pasaje-venta: travel date, delivery / purchase
 *    date, name, phone, WEB / RED BUS) -> transport_online_sales. Files overlap (by travel date and by purchase date, several
 *    periods): a row is unique by channel + dates + name + phone + its occurrence in the file, so re-importing never doubles.
 *  - rebuild(): with $phones, every online sale gives the passengers their phone (AuraSoftPassengers::phones, the whole set);
 *    then each Control de Embarque ticket gets its name key and its passenger (same name; two passengers with one name =
 *    the one who bought last) and each online sale its ticket (same travel day + name). Repeatable; the nightly boarding
 *    import runs it without $phones.
 */
class TransportPassengers
{
    /** @return array{file:string, rows:int, new:int, channel:string, from:?string, to:?string} */
    public static function importOnlinePdf(int $cid, string $file): array
    {
        $lines = PdfText::lines($file);
        $recs = [];
        $cur = null;
        $date = fn ($l) => preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?: \d{2}:\d{2}:\d{2})?$#', $l, $a) ? sprintf('%04d-%02d-%02d', $a[3], $a[2], $a[1]) : null;
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $l = trim($lines[$i]);
            $t = $date($l);
            $s = $t && $i + 1 < $n ? $date(trim($lines[$i + 1])) : null;
            if ($t && $s) {
                $cur = ['travel' => $t, 'sold' => $s, 'name' => [], 'phone' => null];
                $i++;

                continue;
            }
            if (! $cur) {
                continue;
            }
            if (preg_match('/^\+?\d{7,15}$/', $l)) {
                $cur['phone'] = $l;

                continue;
            }
            $type = strtoupper(str_replace(' ', '', $l));
            if (in_array($type, ['WEB', 'REDBUS', 'KUPOS'], true)) {
                $cur['channel'] = strtolower($type);
                $recs[] = $cur;
                $cur = null;

                continue;
            }
            $cur['name'][] = $l;
        }
        $seen = [];
        $rows = [];
        foreach ($recs as $r) {
            $name = AuraSoftPassengers::name(implode(' ', $r['name']));
            $key = AuraSoftPassengers::nameKey($name);
            if ($key === '') {
                continue;
            }
            $phone = $r['phone'] ? (AuraSoftPassengers::phone($r['phone']) ?: null) : null;
            $u = implode('|', [$r['channel'], $r['travel'], $r['sold'], $key, $phone]);
            $seen[$u] = ($seen[$u] ?? 0) + 1;
            $rows[] = ['company_id' => $cid, 'channel' => $r['channel'], 'travel_date' => $r['travel'], 'sold_date' => $r['sold'],
                'passenger' => mb_substr($name, 0, 150), 'name_key' => $key, 'phone' => $phone, 'occurrence' => $seen[$u],
                'source_file' => mb_substr(basename($file), 0, 190), 'created_at' => now()];
        }
        $before = DB::table('transport_online_sales')->where('company_id', $cid)->count();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('transport_online_sales')->insertOrIgnore($chunk);
        }
        $travel = array_column($rows, 'travel_date');

        return ['file' => basename($file), 'rows' => count($rows), 'new' => DB::table('transport_online_sales')->where('company_id', $cid)->count() - $before,
            'channel' => implode('/', array_unique(array_column($rows, 'channel'))), 'from' => $travel ? min($travel) : null, 'to' => $travel ? max($travel) : null];
    }

    /** @return array{phones: ?array, keys:int, tickets_linked:int, tickets:int, online:int, online_linked:int} */
    public static function rebuild(int $cid, bool $phones = false): array
    {
        // 1. The phones of the online sales lists (the whole set: online_tickets is overwritten).
        $ph = null;
        if ($phones) {
            $records = DB::table('transport_online_sales')->where('company_id', $cid)->orderBy('sold_date')->get(['passenger', 'phone', 'sold_date', 'travel_date'])
                ->map(fn ($r) => ['name' => $r->passenger, 'phone' => (string) $r->phone,
                    'emitted' => $r->sold_date ? date('d/m/Y', strtotime($r->sold_date)) : '', 'travel' => $r->travel_date])->all();
            $ph = DB::transaction(fn () => AuraSoftPassengers::phones($cid, $records));
        }

        // 2. The name key of the tickets that don't have it yet (a re-imported day comes back without it).
        $done = 0;
        DB::table('transport_boardings')->where('company_id', $cid)->whereNull('name_key')->select(['id', 'passenger'])
            ->chunkById(2000, function ($rows) use (&$done) {
                $case = [];
                $bind = [];
                foreach ($rows as $r) {
                    $case[] = 'WHEN ? THEN ?';
                    array_push($bind, $r->id, AuraSoftPassengers::nameKey((string) $r->passenger));
                }
                $ids = $rows->pluck('id')->all();
                DB::update('UPDATE transport_boardings SET name_key = CASE id '.implode(' ', $case).' END WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                    array_merge($bind, $ids));
                $done += count($ids);
            });

        // 2b. Every traveller is a passenger: a name of the tickets not in the base joins it by name (no DNI yet; the
        // Liquidación Detallada gives it its DNI when a document brings the same name -- AuraSoftPassengers::sync).
        $added = DB::affectingStatement("INSERT INTO transport_passengers (company_id, doc, name, name_key, is_company, first_purchase, last_purchase,
                purchases, parcels, amount, online_tickets, created_at, updated_at)
            SELECT b.company_id, NULL, TRIM(REPLACE(REPLACE(MAX(b.passenger), ',', ' '), '  ', ' ')), b.name_key, 0, MIN(DATE(b.sold_at)), MAX(DATE(b.sold_at)),
                0, 0, 0, 0, NOW(), NOW()
            FROM transport_boardings b
            WHERE b.company_id = ? AND b.name_key IS NOT NULL AND b.name_key LIKE '% %'
              AND NOT EXISTS (SELECT 1 FROM transport_passengers p WHERE p.company_id = b.company_id AND p.name_key = b.name_key COLLATE utf8mb4_unicode_ci)
            GROUP BY b.company_id, b.name_key", [$cid]);
        if ($added) {
            AuraSoftPassengers::refreshKind($cid);
        }

        // 3. Each ticket to its passenger: same name key; two passengers with one name = the one who bought last.
        DB::statement("UPDATE transport_boardings b
            JOIN (SELECT name_key, CAST(SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY last_purchase DESC, id DESC), ',', 1) AS UNSIGNED) pid
                  FROM transport_passengers WHERE company_id = ? AND name_key <> '' GROUP BY name_key) p ON p.name_key = b.name_key COLLATE utf8mb4_unicode_ci
            SET b.passenger_id = p.pid WHERE b.company_id = ? AND (b.passenger_id IS NULL OR b.passenger_id <> p.pid)", [$cid, $cid]);

        // 4. Each online sale to its ticket: same travel day and name key, the ticket of the same channel first.
        $days = DB::table('transport_online_sales')->where('company_id', $cid)->distinct()->orderBy('travel_date')->pluck('travel_date');
        foreach ($days as $day) {
            $byKey = [];
            foreach (DB::table('transport_boardings')->where('company_id', $cid)->where('travel_date', $day)->get(['id', 'name_key', 'seller']) as $t) {
                $byKey[$t->name_key][] = $t;
            }
            $used = [];
            foreach (DB::table('transport_online_sales')->where('company_id', $cid)->where('travel_date', $day)->orderBy('id')->get(['id', 'channel', 'name_key', 'boarding_id']) as $s) {
                $list = $byKey[$s->name_key] ?? [];
                usort($list, fn ($a, $b) => self::sameChannel($b->seller, $s->channel) <=> self::sameChannel($a->seller, $s->channel));
                $hit = null;
                foreach ($list as $t) {
                    if (! isset($used[$t->id])) {
                        $hit = $t;
                        break;
                    }
                }
                $hit && $used[$hit->id] = true;
                if (($hit->id ?? null) != $s->boarding_id) {
                    DB::table('transport_online_sales')->where('id', $s->id)->update(['boarding_id' => $hit->id ?? null]);
                }
            }
        }

        $b = DB::table('transport_boardings')->where('company_id', $cid)->selectRaw('COUNT(*) n, SUM(passenger_id IS NOT NULL) l')->first();
        $o = DB::table('transport_online_sales')->where('company_id', $cid)->selectRaw('COUNT(*) n, SUM(boarding_id IS NOT NULL) l')->first();

        return ['phones' => $ph, 'keys' => $done, 'added' => $added, 'tickets' => (int) $b->n, 'tickets_linked' => (int) $b->l, 'online' => (int) $o->n, 'online_linked' => (int) $o->l];
    }

    private static function sameChannel(?string $seller, string $channel): int
    {
        $s = strtolower(str_replace(' ', '', (string) $seller));

        return (int) ($s === $channel || str_contains($s, $channel));
    }
}
