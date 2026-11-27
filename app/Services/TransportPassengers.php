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

        // 2a. The travellers' documents (Jelaf's Pasajeros Frecuentes, transport_ticket_docs, 2026-11-26): one passenger per
        // DNI with the name, phone and email of their latest ticket; each Control de Embarque ticket goes to it by number.
        [$ticketPid, $docNew] = self::passengersByDoc($cid);

        // 2b. Every traveller is a passenger: a name of the tickets not in the base joins it by name (no DNI yet; the
        // Liquidación Detallada gives it its DNI when a document brings the same name -- AuraSoftPassengers::sync).
        // Done in PHP and small batches: Hostinger stops a statement that runs too long (max_statement_time, 2026-11-26).
        $pid = [];   // name_key => passenger id (two with one name: the one who bought last)
        $last = [];
        foreach (DB::table('transport_passengers')->where('company_id', $cid)->where('name_key', '!=', '')->select(['id', 'name_key', 'last_purchase'])->cursor() as $p) {
            $lp = (string) $p->last_purchase;
            if (! isset($pid[$p->name_key]) || $lp > $last[$p->name_key] || ($lp === $last[$p->name_key] && $p->id > $pid[$p->name_key])) {
                $pid[$p->name_key] = $p->id;
                $last[$p->name_key] = $lp;
            }
        }
        unset($last);
        $new = [];
        // Only names of tickets without a document (a ticket with one already has its passenger by DNI).
        $docTickets = DB::table('transport_ticket_docs')->where('company_id', $cid)->whereNotNull('doc')->select('ticket');
        foreach (DB::table('transport_boardings')->where('company_id', $cid)->whereNotNull('name_key')->whereNotIn('ticket', $docTickets)->groupBy('name_key')
            ->selectRaw('name_key, MAX(passenger) passenger, MIN(DATE(sold_at)) d1, MAX(DATE(sold_at)) d2')->cursor() as $b) {
            if (! isset($pid[$b->name_key]) && str_contains((string) $b->name_key, ' ')) {
                $new[] = ['company_id' => $cid, 'doc' => null, 'name' => AuraSoftPassengers::name((string) $b->passenger), 'name_key' => $b->name_key,
                    'is_company' => 0, 'first_purchase' => $b->d1, 'last_purchase' => $b->d2, 'purchases' => 0, 'parcels' => 0, 'amount' => 0,
                    'online_tickets' => 0, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($new, 1000) as $chunk) {
            DB::table('transport_passengers')->insert($chunk);
        }
        $added = count($new);
        unset($new);
        if ($added) {
            AuraSoftPassengers::refreshKind($cid);
            foreach (DB::table('transport_passengers')->where('company_id', $cid)->whereNull('doc')->where('name_key', '!=', '')->select(['id', 'name_key'])->cursor() as $p) {
                $pid[$p->name_key] ??= $p->id;
            }
        }

        // 3. Each ticket to its passenger: by its document first, else by name.
        DB::table('transport_boardings')->where('company_id', $cid)->whereNotNull('name_key')->select(['id', 'ticket', 'name_key', 'passenger_id'])
            ->chunkById(2000, function ($rows) use ($pid, $ticketPid) {
                $case = [];
                $bind = [];
                $ids = [];
                foreach ($rows as $r) {
                    $p = $ticketPid[strtoupper(trim((string) $r->ticket))] ?? $pid[$r->name_key] ?? null;
                    if ($p && $p != $r->passenger_id) {
                        $case[] = 'WHEN ? THEN ?';
                        array_push($bind, $r->id, $p);
                        $ids[] = $r->id;
                    }
                }
                if ($ids) {
                    DB::update('UPDATE transport_boardings SET passenger_id = CASE id '.implode(' ', $case).' END WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                        array_merge($bind, $ids));
                }
            });

        // 3b. The passengers made by name earlier whose tickets now have a DNI are left with nothing: removed (only those
        // that are just a name -- no document, phone, purchases, parcels or online tickets).
        $used = array_flip(DB::table('transport_boardings')->where('company_id', $cid)->whereNotNull('passenger_id')->distinct()->pluck('passenger_id')->all());
        $drop = [];
        foreach (DB::table('transport_passengers')->where('company_id', $cid)->whereNull('doc')->whereNull('phone')->where('purchases', 0)
            ->where('parcels', 0)->where('online_tickets', 0)->select('id')->cursor() as $p) {
            isset($used[$p->id]) || $drop[] = $p->id;
        }
        foreach (array_chunk($drop, 1000) as $chunk) {
            DB::table('transport_passengers')->whereIn('id', $chunk)->delete();
        }
        unset($used);

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

        return ['phones' => $ph, 'keys' => $done, 'added' => $added, 'doc_new' => $docNew, 'removed' => count($drop),
            'by_doc' => DB::table('transport_passengers')->where('company_id', $cid)->whereNotNull('doc')->count(), 'tickets' => (int) $b->n, 'tickets_linked' => (int) $b->l, 'online' => (int) $o->n, 'online_linked' => (int) $o->l];
    }

    /**
     * Jelaf's "Reporte de Pasajeros Frecuentes" (Formato detallado) -> transport_ticket_docs, one row per ticket (re-imports
     * update it). Columns read by their header: SERIE, NUMERO, F. VENTA, F. VIAJE, ORIGEN, DESTINO, EMBARQUE, DOCUMENTO,
     * NRO DOC, NOMBRE, MANIFIESTO, ASIENTO, TELEF, CORREO, CANAL, TOTAL.
     *
     * @return array{rows:int, new:int}
     */
    public static function importFrequentFile(int $cid, string $file): array
    {
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file)->setReadDataOnly(true)->load($file);
        $rows = $book->getActiveSheet()->toArray(null, false, false, false);
        $book->disconnectWorksheets();
        unset($book);
        $h = null;
        $out = [];
        $date = function ($v) {
            if (is_numeric($v) && $v > 30000) {
                return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
            }

            return preg_match('#(\d{1,2})/(\d{1,2})/(\d{4})#', (string) $v, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        };
        foreach ($rows as $r) {
            $c = array_map(fn ($x) => $x === null ? '' : trim((string) $x), $r);
            if ($h === null) {
                if (in_array('SERIE', $c, true) && in_array('NUMERO', $c, true)) {
                    $h = array_flip($c);
                }
                continue;
            }
            $g = fn ($k) => isset($h[$k]) ? ($c[$h[$k]] ?? '') : '';
            if ($g('SERIE') === '' || $g('NUMERO') === '') {
                continue;
            }
            $doc = preg_replace('/\s+/', '', $g('NRO DOC'));
            $doc = $doc === '' || $doc === '-' || preg_match('/^0+$/', $doc) ? null : mb_substr($doc, 0, 20);
            $email = strtolower($g('CORREO'));
            $out[] = ['company_id' => $cid, 'ticket' => strtoupper($g('SERIE')).'-'.str_pad(ltrim($g('NUMERO'), '0') ?: '0', 8, '0', STR_PAD_LEFT),
                'travel_date' => $date($r[$h['F. VIAJE']] ?? null), 'sold_date' => $date($r[$h['F. VENTA']] ?? null),
                'origin' => mb_substr($g('ORIGEN'), 0, 40) ?: null, 'destination' => mb_substr($g('DESTINO'), 0, 40) ?: null,
                'boarding_point' => mb_substr($g('EMBARQUE'), 0, 40) ?: null, 'doc_type' => mb_substr($g('DOCUMENTO'), 0, 20) ?: null, 'doc' => $doc,
                'name' => mb_substr(AuraSoftPassengers::name($g('NOMBRE')), 0, 150) ?: null, 'phone' => AuraSoftPassengers::phone($g('TELEF')) ?: null,
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 120) : null, 'channel' => mb_substr($g('CANAL'), 0, 40) ?: null,
                'manifest' => mb_substr($g('MANIFIESTO'), 0, 30) ?: null, 'seat' => mb_substr($g('ASIENTO'), 0, 10) ?: null,
                'total' => (float) str_replace(',', '', $g('TOTAL')), 'created_at' => now(), 'updated_at' => now()];
        }
        $before = DB::table('transport_ticket_docs')->where('company_id', $cid)->count();
        foreach (array_chunk($out, 500) as $chunk) {
            DB::table('transport_ticket_docs')->upsert($chunk, ['company_id', 'ticket'],
                ['travel_date', 'sold_date', 'origin', 'destination', 'boarding_point', 'doc_type', 'doc', 'name', 'phone', 'email', 'channel', 'manifest', 'seat', 'total', 'updated_at']);
        }

        return ['rows' => count($out), 'new' => DB::table('transport_ticket_docs')->where('company_id', $cid)->count() - $before];
    }

    /**
     * One passenger per document from transport_ticket_docs (new ones created; existing ones get a phone / email they lacked)
     * and the map ticket => passenger id.
     *
     * @return array{0: array<string,int>, 1: int}
     */
    private static function passengersByDoc(int $cid): array
    {
        $people = [];
        foreach (DB::table('transport_ticket_docs')->where('company_id', $cid)->whereNotNull('doc')->orderBy('travel_date')->orderBy('id')
            ->select(['doc', 'name', 'phone', 'email', 'travel_date'])->cursor() as $t) {
            $p = &$people[$t->doc];
            $t->name && $p['name'] = $t->name;
            if ($t->phone) {
                $p['phone'] = $t->phone;
                $p['phones'][$t->phone] = true;
            }
            $t->email && $p['email'] = $t->email;
            $p['d1'] ??= $t->travel_date;
            $p['d2'] = $t->travel_date;
            unset($p);
        }
        $byDoc = DB::table('transport_passengers')->where('company_id', $cid)->whereNotNull('doc')->pluck('id', 'doc')->all();
        $now = now();
        $ins = [];
        $fill = [];
        foreach ($people as $doc => $p) {
            $name = AuraSoftPassengers::name((string) ($p['name'] ?? '')) ?: (string) $doc;
            if (! isset($byDoc[$doc])) {
                $ins[] = ['company_id' => $cid, 'doc' => (string) $doc, 'name' => $name, 'name_key' => AuraSoftPassengers::nameKey($name), 'is_company' => strlen((string) $doc) === 11,
                    'phone' => $p['phone'] ?? null, 'phones' => isset($p['phones']) ? json_encode(array_keys($p['phones'])) : null, 'email' => $p['email'] ?? null,
                    'first_purchase' => $p['d1'], 'last_purchase' => $p['d2'], 'purchases' => 0, 'parcels' => 0, 'amount' => 0, 'online_tickets' => 0,
                    'created_at' => $now, 'updated_at' => $now];
            } elseif (! empty($p['phone']) || ! empty($p['email'])) {
                $fill[$byDoc[$doc]] = [$p['phone'] ?? null, $p['email'] ?? null];
            }
        }
        foreach (array_chunk($ins, 1000) as $chunk) {
            DB::table('transport_passengers')->insertOrIgnore($chunk);
        }
        // A phone / email only where the passenger had none (the Liquidación / web lists may already have given one).
        foreach (array_chunk($fill, 1000, true) as $chunk) {
            foreach (['phone' => 0, 'email' => 1] as $col => $i) {
                $case = [];
                $bind = [];
                foreach ($chunk as $id => $v) {
                    if ($v[$i]) {
                        $case[] = 'WHEN ? THEN ?';
                        array_push($bind, $id, $v[$i]);
                    }
                }
                if ($case) {
                    $ids = array_keys($chunk);
                    DB::update("UPDATE transport_passengers SET $col = CASE id ".implode(' ', $case)." ELSE $col END WHERE $col IS NULL AND id IN (".implode(',', array_fill(0, count($ids), '?')).')',
                        array_merge($bind, $ids));
                }
            }
        }
        if ($ins) {
            AuraSoftPassengers::refreshKind($cid);
            $byDoc = DB::table('transport_passengers')->where('company_id', $cid)->whereNotNull('doc')->pluck('id', 'doc')->all();
        }
        $ticketPid = [];
        foreach (DB::table('transport_ticket_docs')->where('company_id', $cid)->whereNotNull('doc')->select(['ticket', 'doc'])->cursor() as $t) {
            isset($byDoc[$t->doc]) && $ticketPid[$t->ticket] = $byDoc[$t->doc];
        }

        return [$ticketPid, count($ins)];
    }

    private static function sameChannel(?string $seller, string $channel): int
    {
        $s = strtolower(str_replace(' ', '', (string) $seller));

        return (int) ($s === $channel || str_contains($s, $channel));
    }
}
