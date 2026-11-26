<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Programación de buses (2026-11-25, Turismo Cautivo's head of operations). A plan is the base timetable (kind base, by
 * day type: weekday = Monday to Saturday, sunday) or a season (kind season, by date: Fiestas Patrias, December...). Each
 * departure is a slot: side (lima / chiclayo = the end it leaves from), terminal, time, route, tag (EJEC, PREM, TRUX, R,
 * OPC...), bus. A day also has its prices per route key (CIX / TRUX) and seat class (SEMICAMA / SOFACAMA) and the buses
 * each city has ({lima, chiclayo}, e.g. "8+3"). The screen warns, per day, when a city has more departures than buses, a
 * bus leaves twice, or the day goes over the buses the plan counts on.
 *
 * Imports the two workbooks Operaciones uses today: "Operaciones - Daniel.xlsx" (sheet Salidas: the base timetable) and
 * the season grids (DICIEMBRE 2025.xlsx, FIESTAS PATRIAS 2026.xlsx: a block per day, a column per Lima terminal, times in
 * rows, the tag on the left, prices below, the buses per city in another sheet).
 */
class TransportSchedule
{
    public const TERMINALS_LIMA = ['CAVASSA', 'PZ NORTE', 'OLIVOS'];

    public const TERMINALS_CIX = ['CIX', 'LAMBAYEQUE'];

    private const MONTHS = ['ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6, 'JULIO' => 7, 'AGOSTO' => 8,
        'SETIEMBRE' => 9, 'SEPTIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12, 'FIESTAS PATRIAS' => 7, 'NAVIDAD' => 12];

    // ------------------------------------------------------------------ reading

    public static function plans(int $cid): array
    {
        $counts = DB::table('transport_schedule_slots')->where('company_id', $cid)->groupBy('plan_id')->selectRaw('plan_id, count(*) n')->pluck('n', 'plan_id');

        return DB::table('transport_schedule_plans')->where('company_id', $cid)->whereNull('deleted_at')
            ->orderByRaw("kind = 'base' DESC")->orderByDesc('date_from')->orderBy('name')
            ->get(['id', 'name', 'kind', 'date_from', 'date_to', 'buses', 'source_file', 'updated_at'])
            ->map(fn ($p) => (array) $p + ['slots' => (int) ($counts[$p->id] ?? 0)])->all();
    }

    public static function plan(int $cid, int $id): object
    {
        $p = DB::table('transport_schedule_plans')->where('company_id', $cid)->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($p, 404, __('Plan not found.'));

        return $p;
    }

    /** The days of a plan: dates of a season, or the two day types of the base timetable. */
    public static function days(object $p): array
    {
        if ($p->kind === 'base') {
            return [['key' => 'weekday', 'label' => 'Lunes a sábado', 'weekday' => null], ['key' => 'sunday', 'label' => 'Domingo', 'weekday' => 7]];
        }
        $out = [];
        if ($p->date_from && $p->date_to) {
            $names = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
            for ($d = Carbon::parse($p->date_from); $d->lte(Carbon::parse($p->date_to)) && count($out) < 120; $d->addDay()) {
                $out[] = ['key' => $d->toDateString(), 'label' => $names[$d->isoWeekday()].' '.$d->format('d'), 'weekday' => $d->isoWeekday()];
            }
        }

        return $out;
    }

    public static function show(int $cid, int $id): array
    {
        $p = self::plan($cid, $id);
        $days = self::days($p);
        $vehicles = self::vehicles($cid);
        $routes = self::routes($cid);
        $slots = DB::table('transport_schedule_slots')->where('company_id', $cid)->where('plan_id', $p->id)->orderBy('time')->get();
        $fleet = json_decode((string) $p->fleet, true) ?: [];
        $byDay = [];
        foreach ($slots as $s) {
            $key = $s->day ?: $s->day_type;
            $v = $s->vehicle_id ? ($vehicles[$s->vehicle_id] ?? null) : null;
            $byDay[$key][] = [
                'id' => $s->id, 'side' => $s->side, 'terminal' => $s->terminal, 'time' => substr($s->time, 0, 5), 'route_id' => $s->route_id,
                'route' => $routes[$s->route_id]['label'] ?? null, 'tag' => $s->tag, 'vehicle_id' => $s->vehicle_id, 'bus' => $v['name'] ?? null,
                'plate' => $v['plate'] ?? null, 'seats' => $v['seats'] ?? null, 'note' => $s->note,
                'boardings' => json_decode((string) $s->boardings, true) ?: [],
            ];
        }
        $prices = [];
        foreach (DB::table('transport_schedule_prices')->where('company_id', $cid)->where('plan_id', $p->id)->get() as $r) {
            $prices[$r->day ?: $r->day_type][$r->route_key][$r->seat_class] = $r->price_text ?: ($r->price !== null ? (float) $r->price : null);
        }
        $stats = [];
        foreach ($days as $d) {
            $list = $byDay[$d['key']] ?? [];
            $lima = count(array_filter($list, fn ($s) => $s['side'] === 'lima'));
            $cix = count(array_filter($list, fn ($s) => $s['side'] === 'chiclayo'));
            $f = $fleet[$d['key']] ?? [];
            $alerts = [];
            $fl = self::sumText($f['lima'] ?? null);
            $fc = self::sumText($f['chiclayo'] ?? null);
            if ($fl !== null && $lima > $fl) {
                $alerts[] = "Lima: $lima salidas y $fl buses";
            }
            if ($fc !== null && $cix > $fc) {
                $alerts[] = "Chiclayo: $cix salidas y $fc buses";
            }
            if ($p->buses && $lima > $p->buses) {
                $alerts[] = "Más salidas ($lima) que buses del plan ($p->buses)";
            }
            // A bus leaving twice from the same end the same day (from Lima and from Chiclayo on one day type is its rotation).
            foreach (['lima' => 'Lima', 'chiclayo' => 'Chiclayo'] as $side => $sideName) {
                $buses = array_count_values(array_filter(array_column(array_filter($list, fn ($s) => $s['side'] === $side), 'bus')));
                foreach ($buses as $bus => $n) {
                    if ($n > 1) {
                        $alerts[] = "$bus sale $n veces de $sideName";
                    }
                }
            }
            $stats[$d['key']] = ['lima' => $lima, 'chiclayo' => $cix, 'fleet' => ['lima' => $f['lima'] ?? null, 'chiclayo' => $f['chiclayo'] ?? null], 'alerts' => $alerts];
        }
        $terminals = self::TERMINALS_LIMA;
        foreach ($slots as $s) {
            if ($s->side === 'lima' && ! in_array($s->terminal, $terminals, true)) {
                $terminals[] = $s->terminal;
            }
        }
        $used = array_unique($slots->where('side', 'lima')->pluck('terminal')->all());

        return [
            'plan' => ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'date_from' => $p->date_from, 'date_to' => $p->date_to,
                'buses' => $p->buses, 'notes' => $p->notes, 'source_file' => $p->source_file],
            'days' => $days, 'slots' => $byDay, 'prices' => $prices, 'stats' => $stats,
            // OLIVOS only when the plan uses it (normal days board at Cavassa and Plaza Norte).
            'terminals' => ['lima' => array_values(array_filter($terminals, fn ($t) => $t !== 'OLIVOS' || in_array('OLIVOS', $used, true))),
                'chiclayo' => self::TERMINALS_CIX],
            'has_chiclayo' => $slots->where('side', 'chiclayo')->isNotEmpty(),
            'vehicles' => array_values($vehicles), 'routes' => array_values($routes),
            'tags' => array_values(array_unique(array_merge(['EJEC', 'EJEC VIP', 'PREM', 'MASTER', 'TRUX', 'R', 'OPC'], $slots->pluck('tag')->filter()->all()))),
        ];
    }

    /** "8+3" -> 11, "9" -> 9, empty -> null. */
    private static function sumText($t): ?int
    {
        if ($t === null || trim((string) $t) === '') {
            return null;
        }
        preg_match_all('/\d+/', (string) $t, $m);

        return $m[0] ? array_sum(array_map('intval', $m[0])) : null;
    }

    /** id => {id, name, plate, seats} of the company's buses ("Bus 170"...), in their number order. */
    public static function vehicles(int $cid): array
    {
        return DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->orderByRaw('CAST(REGEXP_SUBSTR(name, "[0-9]+") AS UNSIGNED)')->orderBy('name')
            ->get(['id', 'name', 'plate_number', 'seats', 'image', 'make', 'model', 'year'])->mapWithKeys(fn ($v) => [$v->id => ['id' => $v->id, 'name' => $v->name, 'plate' => $v->plate_number, 'seats' => $v->seats,
                'image' => $v->image ? '/'.ltrim($v->image, '/') : null, 'model' => trim(implode(' ', array_filter([$v->make, $v->model, $v->year]))) ?: null]])->all();
    }

    /** id => {id, label, origin, destination} of the company's routes. */
    public static function routes(int $cid): array
    {
        return DB::table('transport_routes as r')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->where('r.company_id', $cid)->whereNull('r.deleted_at')->orderBy('o.line_order')->orderBy('d.line_order')
            ->get(['r.id', 'o.name as o', 'd.name as d'])
            ->mapWithKeys(fn ($r) => [$r->id => ['id' => $r->id, 'label' => $r->o.' → '.$r->d, 'origin' => $r->o, 'destination' => $r->d]])->all();
    }

    private static function routeId(int $cid, string $from, string $to): ?int
    {
        foreach (self::routes($cid) as $r) {
            if (strtoupper($r['origin']) === $from && strtoupper($r['destination']) === $to) {
                return $r['id'];
            }
        }

        return null;
    }

    private static function terminalId(int $cid, string $label): ?int
    {
        $like = ['CAVASSA' => '%CAVASSA%', 'PZ NORTE' => '%PLAZA%NORTE%', 'OLIVOS' => '%OLIVOS%', 'CIX' => '%CIX%', 'LAMBAYEQUE' => 'LAMBAYEQUE%'][$label] ?? null;

        return $like ? (DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('name', 'like', $like)->value('id') ?: null) : null;
    }

    // ------------------------------------------------------------------ writing

    public static function savePlan(int $cid, ?int $id, array $d, int $userId): int
    {
        $row = ['name' => trim((string) $d['name']), 'buses' => isset($d['buses']) && $d['buses'] !== '' ? (int) $d['buses'] : null,
            'notes' => $d['notes'] ?? null, 'updated_at' => now()];
        if ($id) {
            $p = self::plan($cid, $id);
            if ($p->kind === 'season' && ! empty($d['date_from']) && ! empty($d['date_to'])) {
                $row += ['date_from' => $d['date_from'], 'date_to' => $d['date_to']];
            }
            DB::table('transport_schedule_plans')->where('id', $p->id)->update($row);

            return $p->id;
        }
        $kind = ($d['kind'] ?? 'season') === 'base' ? 'base' : 'season';
        if ($kind === 'base') {
            abort_if(DB::table('transport_schedule_plans')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'base')->exists(), 422, __('The base timetable already exists.'));
        } else {
            abort_unless(! empty($d['date_from']) && ! empty($d['date_to']) && $d['date_from'] <= $d['date_to'], 422, __('Give the dates of the season.'));
            abort_if(Carbon::parse($d['date_from'])->diffInDays(Carbon::parse($d['date_to'])) > 92, 422, __('A season can have up to 3 months.'));
        }

        return DB::transaction(function () use ($cid, $kind, $row, $d, $userId) {
            $id = DB::table('transport_schedule_plans')->insertGetId($row + ['company_id' => $cid, 'kind' => $kind,
                'date_from' => $kind === 'season' ? $d['date_from'] : null, 'date_to' => $kind === 'season' ? $d['date_to'] : null,
                'created_by' => $userId, 'created_at' => now()]);
            // A new season starts from the base timetable: Monday-Saturday / Sunday copied onto each date.
            if ($kind === 'season' && ! empty($d['copy_base'])) {
                $base = DB::table('transport_schedule_plans')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'base')->value('id');
                $tpl = $base ? DB::table('transport_schedule_slots')->where('plan_id', $base)->get() : collect();
                $ins = [];
                for ($day = Carbon::parse($d['date_from']); $day->lte(Carbon::parse($d['date_to'])); $day->addDay()) {
                    foreach ($tpl->where('day_type', $day->isSunday() ? 'sunday' : 'weekday') as $s) {
                        $ins[] = ['company_id' => $cid, 'plan_id' => $id, 'day' => $day->toDateString(), 'day_type' => null, 'side' => $s->side,
                            'terminal' => $s->terminal, 'terminal_id' => $s->terminal_id, 'time' => $s->time, 'route_id' => $s->route_id,
                            'tag' => $s->tag, 'vehicle_id' => $s->vehicle_id, 'note' => $s->note, 'boardings' => $s->boardings, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                foreach (array_chunk($ins, 500) as $chunk) {
                    DB::table('transport_schedule_slots')->insert($chunk);
                }
            }

            return $id;
        });
    }

    public static function deletePlan(int $cid, int $id): void
    {
        $p = self::plan($cid, $id);
        DB::table('transport_schedule_plans')->where('id', $p->id)->update(['deleted_at' => now()]);
    }

    public static function saveSlot(int $cid, int $planId, ?int $id, array $d): int
    {
        $p = self::plan($cid, $planId);
        $time = self::time($d['time'] ?? null);
        abort_unless($time, 422, __('Wrong time.'));
        $side = ($d['side'] ?? 'lima') === 'chiclayo' ? 'chiclayo' : 'lima';
        $terminal = strtoupper(trim((string) ($d['terminal'] ?? '')));
        abort_if($terminal === '', 422, __('Pick the terminal.'));
        $day = (string) ($d['day'] ?? '');
        if ($p->kind === 'base') {
            abort_unless(in_array($day, ['weekday', 'sunday'], true), 422, 'Wrong day.');
        } else {
            abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day >= $p->date_from && $day <= $p->date_to, 422, __('The day is outside the plan.'));
        }
        $vehicle = ! empty($d['vehicle_id']) ? (int) $d['vehicle_id'] : null;
        abort_if($vehicle && ! isset(self::vehicles($cid)[$vehicle]), 422, 'Wrong bus.');
        $route = ! empty($d['route_id']) ? (int) $d['route_id'] : null;
        abort_if($route && ! isset(self::routes($cid)[$route]), 422, 'Wrong route.');
        $more = [];
        foreach ((array) ($d['boardings'] ?? []) as $bd) {
            $bt = strtoupper(trim((string) ($bd['terminal'] ?? '')));
            $tm = self::time($bd['time'] ?? null);
            if ($bt !== '' && $tm && $bt !== $terminal) {
                $more[] = ['terminal' => substr($bt, 0, 40), 'time' => substr($tm, 0, 5)];
            }
        }
        $row = ['boardings' => $more ? json_encode(array_slice($more, 0, 6)) : null,
            'day' => $p->kind === 'base' ? null : $day, 'day_type' => $p->kind === 'base' ? $day : null, 'side' => $side, 'terminal' => $terminal,
            'terminal_id' => self::terminalId($cid, $terminal), 'time' => $time, 'route_id' => $route,
            'tag' => trim((string) ($d['tag'] ?? '')) ?: null, 'vehicle_id' => $vehicle, 'note' => trim((string) ($d['note'] ?? '')) ?: null, 'updated_at' => now()];
        if ($id) {
            $n = DB::table('transport_schedule_slots')->where('company_id', $cid)->where('plan_id', $p->id)->where('id', $id)->update($row);
            abort_unless($n || DB::table('transport_schedule_slots')->where('plan_id', $p->id)->where('id', $id)->exists(), 404, __('Departure not found.'));
            DB::table('transport_schedule_plans')->where('id', $p->id)->update(['updated_at' => now()]);

            return $id;
        }
        DB::table('transport_schedule_plans')->where('id', $p->id)->update(['updated_at' => now()]);

        return DB::table('transport_schedule_slots')->insertGetId($row + ['company_id' => $cid, 'plan_id' => $p->id, 'created_at' => now()]);
    }

    public static function deleteSlot(int $cid, int $planId, int $id): void
    {
        $p = self::plan($cid, $planId);
        DB::table('transport_schedule_slots')->where('company_id', $cid)->where('plan_id', $p->id)->where('id', $id)->delete();
    }

    /** A day's price (route key CIX / TRUX, seat class SEMICAMA / SOFACAMA) and / or the buses each city has. */
    public static function saveDay(int $cid, int $planId, string $day, array $d): void
    {
        $p = self::plan($cid, $planId);
        $isBase = $p->kind === 'base';
        abort_unless($isBase ? in_array($day, ['weekday', 'sunday'], true) : ($day >= $p->date_from && $day <= $p->date_to), 422, __('The day is outside the plan.'));
        DB::transaction(function () use ($cid, $p, $day, $d, $isBase) {
            foreach ((array) ($d['prices'] ?? []) as $routeKey => $classes) {
                foreach ((array) $classes as $class => $value) {
                    $routeKey = strtoupper(substr((string) $routeKey, 0, 20));
                    $class = strtoupper(substr((string) $class, 0, 20));
                    $text = trim((string) $value);
                    $num = preg_match('/^(S\/\s*)?\d+(\.\d+)?$/i', str_replace(',', '', $text)) ? (float) preg_replace('/[^\d.]/', '', $text) : null;
                    $where = ['company_id' => $cid, 'plan_id' => $p->id, 'day' => $isBase ? null : $day, 'day_type' => $isBase ? $day : null, 'route_key' => $routeKey, 'seat_class' => $class];
                    $q = DB::table('transport_schedule_prices')->where($where);
                    if ($text === '') {
                        $q->delete();
                        continue;
                    }
                    $vals = ['price' => $num, 'price_text' => $num === null ? substr($text, 0, 40) : null, 'updated_at' => now()];
                    $q->exists() ? $q->update($vals) : DB::table('transport_schedule_prices')->insert($where + $vals + ['created_at' => now()]);
                }
            }
            if (array_key_exists('fleet', $d)) {
                $fleet = json_decode((string) DB::table('transport_schedule_plans')->where('id', $p->id)->value('fleet'), true) ?: [];
                $fleet[$day] = ['lima' => trim((string) ($d['fleet']['lima'] ?? '')) ?: null, 'chiclayo' => trim((string) ($d['fleet']['chiclayo'] ?? '')) ?: null];
                DB::table('transport_schedule_plans')->where('id', $p->id)->update(['fleet' => json_encode($fleet), 'updated_at' => now()]);
            }
        });
    }

    private static function time($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v < 1) {
            $m = (int) round((float) $v * 1440);

            return sprintf('%02d:%02d:00', intdiv($m, 60) % 24, $m % 60);
        }
        $s = strtoupper(trim(str_replace('.', '', (string) $v)));
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/', $s, $m)) {
            return null;
        }
        $h = (int) $m[1];
        if (($m[3] ?? '') === 'PM' && $h < 12) {
            $h += 12;
        } elseif (($m[3] ?? '') === 'AM' && $h === 12) {
            $h = 0;
        }

        return $h < 24 && (int) $m[2] < 60 ? sprintf('%02d:%02d:00', $h, (int) $m[2]) : null;
    }

    // ------------------------------------------------------------------ imports

    /** "Operaciones - Daniel.xlsx", sheet Salidas: Sentido | Bus habitual | Primer embarque | Hora | ... | service | LUNES A SABADO / DOMINGO. */
    public static function importBase(int $cid, string $path, string $originalName, int $userId): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('Salidas') ?: $book->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        $vehicles = self::vehicles($cid);
        $byNumber = [];
        foreach ($vehicles as $v) {
            if (preg_match('/(\d+)/', $v['name'], $m)) {
                $byNumber[(int) $m[1]] = $v['id'];
            }
        }
        $limaCix = self::routeId($cid, 'LIMA', 'CHICLAYO');
        $cixLima = self::routeId($cid, 'CHICLAYO', 'LIMA');
        $type = 'weekday';
        $out = [];
        foreach ($rows as $i => $r) {
            if ($i === 0) {
                continue;
            }
            $cells = array_map(fn ($x) => trim((string) $x), $r);
            $marker = strtoupper(implode(' ', array_slice($cells, 6)));
            if (str_contains($marker, 'DOMINGO')) {
                $type = 'sunday';
            } elseif (str_contains($marker, 'LUNES')) {
                $type = 'weekday';
            }
            $sense = strtoupper($cells[0] ?? '');
            $time = self::time($r[3] ?? null);
            if (! $time || ! str_contains($sense, 'LIMA')) {
                continue;
            }
            $fromLima = str_starts_with($sense, 'LIMA');
            $first = strtoupper(str_replace(' ', '', $cells[2] ?? ''));
            $terminal = ['P.N.' => 'PZ NORTE', 'PN' => 'PZ NORTE', 'CV' => 'CAVASSA', 'CAVASSA' => 'CAVASSA', 'CIX' => 'CIX', 'LAMBAYEQUE' => 'LAMBAYEQUE', 'OLIVOS' => 'OLIVOS'][$first] ?? ($cells[2] ?: ($fromLima ? 'CAVASSA' : 'CIX'));
            $bus = preg_match('/(\d+)/', $cells[1] ?? '', $m) ? ($byNumber[(int) $m[1]] ?? null) : null;
            $service = strtoupper(trim(preg_replace('/\(.*$/s', '', $cells[5] ?? '')));
            $tag = str_contains($service, 'VIP') ? 'EJEC VIP' : (str_contains($service, 'EJECUTIVO') ? 'EJEC' : (str_contains($service, 'PREMIUM') ? 'PREM' : (str_contains($service, 'MASTER') ? 'MASTER' : ($service ?: null))));
            $note = preg_match('/\((.*)\)/s', $cells[5] ?? '', $m) ? ucfirst(mb_strtolower(trim($m[1]))) : null;
            $out[] = ['day_type' => $type, 'side' => $fromLima ? 'lima' : 'chiclayo', 'terminal' => $terminal, 'time' => $time,
                'route_id' => $fromLima ? $limaCix : $cixLima, 'tag' => $tag, 'vehicle_id' => $bus, 'note' => $note ? mb_substr($note, 0, 250) : null];
        }
        abort_unless($out, 422, __('No departures were found in the file (sheet Salidas).'));

        return DB::transaction(function () use ($cid, $out, $originalName, $userId) {
            $id = DB::table('transport_schedule_plans')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'base')->value('id');
            if ($id) {
                DB::table('transport_schedule_slots')->where('plan_id', $id)->delete();
                DB::table('transport_schedule_plans')->where('id', $id)->update(['source_file' => $originalName, 'updated_at' => now()]);
            } else {
                $id = DB::table('transport_schedule_plans')->insertGetId(['company_id' => $cid, 'name' => 'Horario base (días normales)', 'kind' => 'base',
                    'source_file' => $originalName, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($out as $s) {
                DB::table('transport_schedule_slots')->insert($s + ['company_id' => $cid, 'plan_id' => $id, 'terminal_id' => self::terminalId($cid, $s['terminal']),
                    'day' => null, 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['plan_id' => $id, 'slots' => count($out)];
        });
    }

    /**
     * A season grid (DICIEMBRE 2025.xlsx, FIESTAS PATRIAS 2026.xlsx): "LUNES 15" starts a day block; the next row names the
     * Lima terminals (CAVASA, PZ NORTE, OLIVOS); then a row per time with the tags on the left of the block (EJEC, PREM, TRUX,
     * 260 = bus 260, R, OPC, ???); SEMICAMA / SOFACAMA rows are the day's prices (TRUX on the left = to Trujillo); free text =
     * notes ("SE A PROGRAMADO EN BASE DE 15 BUSES" = buses). A sheet with LIMA / CHICLAYO rows under the days = buses per city.
     * The first day's month and year come from the file name (DICIEMBRE 2025, FIESTAS PATRIAS 2026) unless given.
     */
    public static function importSeason(int $cid, string $path, string $originalName, int $userId, ?int $year = null, ?int $month = null, ?string $name = null): array
    {
        $upperName = mb_strtoupper(pathinfo($originalName, PATHINFO_FILENAME));
        if (! $month) {
            foreach (self::MONTHS as $word => $m) {
                if (str_contains($upperName, $word)) {
                    $month = $m;
                    break;
                }
            }
        }
        if (! $year && preg_match('/(20\d{2})/', $upperName, $m)) {
            $year = (int) $m[1];
        }
        abort_unless($month && $year, 422, __('Give the month and year of the first day.'));

        $book = IOFactory::load($path);
        $vehicles = self::vehicles($cid);
        $byNumber = [];
        foreach ($vehicles as $v) {
            if (preg_match('/(\d+)/', $v['name'], $mm)) {
                $byNumber[(int) $mm[1]] = $v['id'];
            }
        }
        $routeCix = self::routeId($cid, 'LIMA', 'CHICLAYO');
        $routeTrux = self::routeId($cid, 'LIMA', 'TRUJILLO');
        $dayRe = '/^(LUNES|MARTES|MI[EÉ]RCOLES|JUEVES|VIERNES|S[AÁ]BADO|DOMINGO)\s+(\d{1,2})$/u';
        $slots = $prices = $fleet = $notes = [];
        $buses = null;

        // A day number -> a date, moving to the next month when the number goes down (31 -> 01).
        $cursor = ['y' => $year, 'm' => $month, 'last' => 0];
        $dateOf = function (int $dayNum) use (&$cursor) {
            if ($dayNum < $cursor['last']) {
                $cursor['m']++;
                if ($cursor['m'] > 12) {
                    $cursor['m'] = 1;
                    $cursor['y']++;
                }
            }
            $cursor['last'] = $dayNum;

            return sprintf('%04d-%02d-%02d', $cursor['y'], $cursor['m'], $dayNum);
        };

        foreach ($book->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            // Buses per city: a row of "miercoles 22" headers, then LIMA / CHICLAYO rows.
            $fleetHead = null;
            foreach ($rows as $r) {
                $cells = array_map(fn ($x) => trim((string) $x), $r);
                $heads = [];
                foreach ($cells as $c => $v) {
                    if (preg_match($dayRe, mb_strtoupper($v), $m) && ! preg_match('/\s{2,}/', $v)) {
                        $heads[$c] = (int) $m[2];
                    }
                }
                if (count($heads) >= 3 && ! array_filter($cells, fn ($v) => in_array(mb_strtoupper($v), ['CAVASA', 'CAVASSA', 'PZ NORTE'], true))) {
                    $fleetHead = $heads;
                    continue;
                }
                $label = mb_strtoupper($cells[1] ?? $cells[0] ?? '');
                if ($fleetHead && in_array($label, ['LIMA', 'CHICLAYO'], true)) {
                    $save = $cursor;
                    $cursor = ['y' => $year, 'm' => $month, 'last' => 0];
                    foreach ($fleetHead as $c => $dn) {
                        $date = $dateOf($dn);
                        if (($cells[$c] ?? '') !== '') {
                            $fleet[$date][$label === 'LIMA' ? 'lima' : 'chiclayo'] = $cells[$c];
                        }
                    }
                    $cursor = $save;
                }
            }
        }

        // The day blocks (the first sheet that has them).
        foreach ($book->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            $blocks = [];      // [{col, end, date, terms: [col => label], tagCol, route}]
            $foundHere = false;
            foreach ($rows as $r) {
                $cells = array_map(fn ($x) => trim((string) $x), $r);
                $heads = [];
                foreach ($cells as $c => $v) {
                    if (preg_match($dayRe, mb_strtoupper($v), $m)) {
                        $heads[$c] = (int) $m[2];
                    }
                }
                $isTermRow = (bool) array_filter($cells, fn ($v) => in_array(mb_strtoupper($v), ['CAVASA', 'CAVASSA', 'PZ NORTE'], true));
                if ($heads && ! $isTermRow) {
                    // A new row of day headers starts new blocks (a fleet table's days get no terminal row, so no date).
                    $cols = array_keys($heads);
                    $blocks = [];
                    foreach ($cols as $k => $c) {
                        $end = $cols[$k + 1] ?? ($c + (isset($cols[1]) ? $cols[1] - $cols[0] : 5));
                        $blocks[] = ['col' => $c, 'end' => $end - 1, 'date' => null, 'num' => $heads[$c], 'terms' => [], 'route' => 'CIX'];
                    }
                    continue;
                }
                if (! $blocks) {
                    continue;
                }
                if ($isTermRow) {
                    foreach ($blocks as &$b) {
                        for ($c = $b['col'] + 1; $c <= $b['end']; $c++) {
                            $v = mb_strtoupper($cells[$c] ?? '');
                            if (in_array($v, ['CAVASA', 'CAVASSA'], true)) {
                                $b['terms'][$c] = 'CAVASSA';
                            } elseif ($v === 'PZ NORTE') {
                                $b['terms'][$c] = 'PZ NORTE';
                            } elseif (str_contains($v, 'OLIVOS')) {
                                $b['terms'][$c] = 'OLIVOS';
                            }
                        }
                        if ($b['terms'] && ! $b['date']) {
                            $b['date'] = $dateOf($b['num']);
                            $foundHere = true;
                        }
                    }
                    unset($b);
                    continue;
                }
                foreach ($blocks as &$b) {
                    if (! $b['date']) {
                        continue;
                    }
                    $range = [];
                    for ($c = $b['col']; $c <= $b['end']; $c++) {
                        $range[$c] = $cells[$c] ?? '';
                    }
                    $text = mb_strtoupper(implode(' ', array_filter($range)));
                    if ($text === '') {
                        continue;
                    }
                    if (str_contains($text, 'SEMICAMA') || str_contains($text, 'SOFACAMA')) {
                        $class = str_contains($text, 'SEMICAMA') ? 'SEMICAMA' : 'SOFACAMA';
                        if ($class === 'SEMICAMA') {
                            $b['route'] = str_contains($text, 'TRUX') ? 'TRUX' : 'CIX';
                        }
                        $after = false;
                        foreach ($range as $c => $v) {
                            if ($after && $v !== '') {
                                $prices[$b['date']][$b['route']][$class] = $v;
                                break;
                            }
                            if (in_array(mb_strtoupper($v), ['SEMICAMA', 'SOFACAMA'], true)) {
                                $after = true;
                            }
                        }
                        continue;
                    }
                    // A long text is a note, never a departure.
                    foreach ($range as $v) {
                        if (mb_strlen($v) > 12 && ! self::time($v)) {
                            $notes[] = $v;
                        }
                    }
                    $times = [];
                    $tags = [];
                    foreach ($range as $c => $v) {
                        if ($v === '' || $v === '*') {
                            continue;
                        }
                        $t = self::time($v);
                        if ($t && isset($b['terms'][$c])) {
                            $times[$c] = $t;
                        } elseif (! $t && ($c === $b['col'] || isset($b['terms'][$c])) && mb_strlen($v) <= 12) {
                            $tags[] = mb_strtoupper($v);
                        }
                    }
                    // One row = one bus: its first (earliest) time is the departure, a time at another terminal on the same
                    // row is the same bus boarding there later (Cavassa 5:30 PM -> Plaza Norte 7:00 PM). Daniel's "buses per
                    // city" sheet counts it once (2026-11-25: 8 rows on 23-07 = his 8 buses from Lima).
                    if ($times) {
                        asort($times);
                        $bus = null;
                        $tagList = [];
                        foreach ($tags as $tg) {
                            if (preg_match('/^\d{3}$/', $tg) && isset($byNumber[(int) $tg])) {
                                $bus = $byNumber[(int) $tg];
                            }
                            $tagList[] = ['PRM' => 'PREM', 'PRE' => 'PREM', 'EJE' => 'EJEC'][$tg] ?? $tg;
                        }
                        $isTrux = in_array('TRUX', $tagList, true);
                        $first = array_key_first($times);
                        $more = [];
                        foreach ($times as $c => $t) {
                            if ($c !== $first) {
                                $more[] = ['terminal' => $b['terms'][$c], 'time' => substr($t, 0, 5)];
                            }
                        }
                        $slots[] = ['day' => $b['date'], 'side' => 'lima', 'terminal' => $b['terms'][$first], 'time' => $times[$first],
                            'route_id' => $isTrux ? $routeTrux : $routeCix, 'tag' => $tagList ? implode(' ', array_unique($tagList)) : null, 'vehicle_id' => $bus,
                            'boardings' => $more ? json_encode($more) : null];
                    }
                }
                unset($b);
            }
            if ($foundHere) {
                break;
            }
        }
        abort_unless($slots, 422, __('No departures were found in the file.'));
        foreach (array_unique($notes) as $n) {
            if (preg_match('/BASE DE (\d+) BUSES/i', $n, $m)) {
                $buses = (int) $m[1];
            }
        }
        $dates = array_unique(array_column($slots, 'day'));
        sort($dates);
        $title = $name ?: (str_contains($upperName, 'PATRIAS') ? 'Fiestas Patrias '.$year : ucfirst(mb_strtolower(array_search($month, self::MONTHS, true) ?: 'Temporada')).' '.$year);

        return DB::transaction(function () use ($cid, $slots, $prices, $fleet, $notes, $buses, $dates, $title, $originalName, $userId) {
            $id = DB::table('transport_schedule_plans')->insertGetId([
                'company_id' => $cid, 'name' => $title, 'kind' => 'season', 'date_from' => reset($dates), 'date_to' => end($dates), 'buses' => $buses,
                'fleet' => $fleet ? json_encode($fleet) : null, 'notes' => $notes ? implode("\n", array_unique($notes)) : null,
                'source_file' => $originalName, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($slots as $s) {
                DB::table('transport_schedule_slots')->insert($s + ['company_id' => $cid, 'plan_id' => $id, 'day_type' => null,
                    'terminal_id' => self::terminalId($cid, $s['terminal']), 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($prices as $day => $routes) {
                foreach ($routes as $rk => $classes) {
                    foreach ($classes as $class => $text) {
                        $clean = str_replace(',', '', trim($text));
                        $num = preg_match('/^(S\/\s*)?\d+(\.\d+)?$/i', $clean) ? (float) preg_replace('/[^\d.]/', '', $clean) : null;
                        DB::table('transport_schedule_prices')->insert(['company_id' => $cid, 'plan_id' => $id, 'day' => $day, 'route_key' => $rk,
                            'seat_class' => $class, 'price' => $num, 'price_text' => $num === null ? mb_substr($text, 0, 40) : null, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }

            return ['plan_id' => $id, 'slots' => count($slots), 'days' => count($dates), 'from' => reset($dates), 'to' => end($dates)];
        });
    }

    // ------------------------------------------------------------------ export

    /** The plan as a list, in the order it is typed into the ticket system (day, side, time, terminal). */
    public static function export(int $cid, int $id): string
    {
        $data = self::show($cid, $id);
        $book = new Spreadsheet;
        $sh = $book->getActiveSheet();
        $sh->setTitle('Programación');
        $sh->fromArray([['Día', 'Sale de', 'Terminal', 'Hora', 'También embarca', 'Ruta', 'Servicio / marca', 'Bus', 'Placa', 'Asientos', 'Nota']], null, 'A1');
        $row = 2;
        foreach ($data['days'] as $d) {
            $list = $data['slots'][$d['key']] ?? [];
            usort($list, fn ($a, $b) => [$a['side'] === 'lima' ? 0 : 1, $a['time'], $a['terminal']] <=> [$b['side'] === 'lima' ? 0 : 1, $b['time'], $b['terminal']]);
            foreach ($list as $s) {
                $sh->fromArray([[
                    $data['plan']['kind'] === 'base' ? $d['label'] : Carbon::parse($d['key'])->format('d/m/Y').' '.$d['label'],
                    $s['side'] === 'lima' ? 'Lima' : 'Chiclayo', $s['terminal'], date('g:i A', strtotime($s['time'])),
                    implode(', ', array_map(fn ($b) => $b['terminal'].' '.date('g:i A', strtotime($b['time'])), $s['boardings'])), $s['route'], $s['tag'],
                    $s['bus'], $s['plate'], $s['seats'], $s['note'],
                ]], null, 'A'.$row++);
            }
        }
        $sh->getStyle('A1:K1')->getFont()->setBold(true);
        foreach (range('A', 'K') as $col) {
            $sh->getColumnDimension($col)->setAutoSize(true);
        }
        $file = tempnam(sys_get_temp_dir(), 'prog').'.xlsx';
        (new Xlsx($book))->save($file);

        return $file;
    }
}
