<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard board "Programación" (user's request, 2026-11-26): a week of bus departures laid out like Operaciones' Excel
 * (a column per day, the Lima side and the north side, departures by time) with what Jelaf has sold for each one, and
 * programming by those sales.
 *
 *  - The PLAN of a date: the season plan that covers it, else the base timetable (Mon-Sat / Sunday) -- TransportSchedule --,
 *    plus the date's own changes (transport_schedule_changes: add a reinforcement, remove, change bus / time / tag).
 *  - JELAF's departures of the date: Control de Embarque (transport_boardings: past days = final, the next 14 days = sold so
 *    far, imported each night), one per "programación".
 *  - Each plan departure is matched to Jelaf's on the same side within 45 minutes (the same bus first). Unmatched: "plan
 *    only" (not open in Jelaf yet / nothing sold) or "Jelaf only" (not in the plan).
 *  - FORECAST of a future departure = sold + (1 - r(d)) x usual: r(d) = the share of a departure's tickets already sold d
 *    days before it (the booking curve of the last 120 days, per side), usual = the average final passengers of that side,
 *    weekday and hour over the last 8 weeks. Suggestion: >= 90 % of the seats -> reinforce; < 30 % -> low (merge / move).
 *  - Bus checks: a bus twice from the same side within 20 h, or from both sides within 10 h (it can't be there).
 */
class TransportProgramming
{
    public const HIGH = 0.9;

    public const LOW = 0.3;

    private const DAY_NAMES = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    public static function side(?string $origin): string
    {
        return strtoupper(trim((string) $origin)) === 'LIMA' ? 'lima' : 'chiclayo';
    }

    /** r(d) per side: the share of the final tickets already sold d days before the departure (d = 0..15). */
    public static function curve(int $cid): array
    {
        return Cache::remember("transport_programming_curve_$cid", 6 * 3600, function () use ($cid) {
            $rows = DB::table('transport_boardings')->where('company_id', $cid)
                ->whereBetween('travel_date', [now()->subDays(120)->toDateString(), now()->subDay()->toDateString()])->whereNotNull('sold_at')
                ->selectRaw("IF(UPPER(origin) = 'LIMA', 'lima', 'chiclayo') s, LEAST(GREATEST(DATEDIFF(travel_date, DATE(sold_at)), 0), 15) l, COUNT(*) n")
                ->groupBy('s', 'l')->get();
            $out = [];
            foreach (['lima', 'chiclayo'] as $side) {
                $by = $rows->where('s', $side)->pluck('n', 'l')->map(fn ($v) => (int) $v)->all();
                $total = array_sum($by) ?: 1;
                for ($d = 0; $d <= 15; $d++) {
                    $ahead = 0;
                    foreach ($by as $l => $n) {
                        $ahead += $l >= $d ? $n : 0;
                    }
                    $out[$side][$d] = round($ahead / $total, 4);
                }
            }

            return $out;
        });
    }

    /** The usual final passengers of a departure by side + weekday + hour (and fallbacks), last 8 weeks. */
    public static function usual(int $cid): array
    {
        return Cache::remember("transport_programming_usual_$cid", 6 * 3600, function () use ($cid) {
            $deps = DB::table('transport_boardings')->where('company_id', $cid)
                ->whereBetween('travel_date', [now()->subDays(56)->toDateString(), now()->subDay()->toDateString()])
                ->selectRaw("travel_date, programacion, IF(UPPER(MAX(origin)) = 'LIMA', 'lima', 'chiclayo') s, HOUR(MIN(departure_time)) h, COUNT(*) n")
                ->groupBy('travel_date', 'programacion')->get();
            $acc = [];
            foreach ($deps as $d) {
                $wd = Carbon::parse($d->travel_date)->isoWeekday();
                foreach (["{$d->s}|$wd|{$d->h}", "{$d->s}|*|{$d->h}", "{$d->s}|$wd|*", "{$d->s}|*|*"] as $k) {
                    $acc[$k][0] = ($acc[$k][0] ?? 0) + $d->n;
                    $acc[$k][1] = ($acc[$k][1] ?? 0) + 1;
                }
            }

            return array_map(fn ($a) => round($a[0] / $a[1], 1), $acc);
        });
    }

    private static function usualFor(array $usual, string $side, int $wd, int $h): float
    {
        return $usual["$side|$wd|$h"] ?? $usual["$side|*|$h"] ?? $usual["$side|$wd|*"] ?? $usual["$side|*|*"] ?? 0.0;
    }

    /** The plan's departures of a date (season covering it, else the base timetable), with the date's changes applied. */
    public static function planFor(int $cid, string $day, array $vehicles): array
    {
        $season = DB::table('transport_schedule_plans')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'season')
            ->where('date_from', '<=', $day)->where('date_to', '>=', $day)->orderByDesc('id')->first();
        $base = $season ? null : DB::table('transport_schedule_plans')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'base')->first();
        $plan = $season ?: $base;
        $slots = collect();
        if ($plan) {
            $slots = DB::table('transport_schedule_slots')->where('company_id', $cid)->where('plan_id', $plan->id)
                ->when($season, fn ($q) => $q->where('day', $day), fn ($q) => $q->where('day_type', Carbon::parse($day)->isSunday() ? 'sunday' : 'weekday'))
                ->orderBy('time')->get();
        }
        $changes = DB::table('transport_schedule_changes')->where('company_id', $cid)->where('day', $day)->orderBy('id')->get();
        $out = [];
        foreach ($slots as $s) {
            $out['s'.$s->id] = ['slot_id' => $s->id, 'change_id' => null, 'side' => $s->side, 'terminal' => $s->terminal, 'time' => substr((string) $s->time, 0, 5),
                'tag' => $s->tag, 'vehicle_id' => $s->vehicle_id, 'boardings' => json_decode((string) $s->boardings, true) ?: [], 'note' => $s->note,
                'changed' => false, 'removed' => false];
        }
        foreach ($changes as $c) {
            if ($c->action === 'add') {
                $out['c'.$c->id] = ['slot_id' => null, 'change_id' => $c->id, 'side' => $c->side, 'terminal' => $c->terminal, 'time' => substr((string) $c->time, 0, 5),
                    'tag' => $c->tag, 'vehicle_id' => $c->vehicle_id, 'boardings' => [], 'note' => $c->note, 'changed' => false, 'removed' => false, 'added' => true];
            } elseif ($c->slot_id && isset($out['s'.$c->slot_id])) {
                $o = &$out['s'.$c->slot_id];
                $o['change_id'] = $c->id;
                $o['note'] = $c->note ?: $o['note'];
                if ($c->action === 'remove') {
                    $o['removed'] = true;
                } else {
                    $o['changed'] = true;
                    $c->vehicle_id && $o['vehicle_id'] = $c->vehicle_id;
                    $c->time && $o['time'] = substr((string) $c->time, 0, 5);
                    $c->tag && $o['tag'] = $c->tag;
                }
                unset($o);
            }
        }

        return ['plan' => $plan ? ['id' => $plan->id, 'name' => $plan->name, 'kind' => $plan->kind] : null, 'departures' => array_values($out)];
    }

    /** Jelaf's departures of the week's dates (one per programación). */
    private static function jelaf(int $cid, string $from, string $to): array
    {
        $rows = DB::table('transport_boardings')->where('company_id', $cid)->whereBetween('travel_date', [$from, $to])
            ->groupBy('travel_date', 'programacion')
            ->selectRaw('travel_date, programacion, MIN(departure_time) t, MAX(origin) origin, MAX(destination) destination, MAX(plate) plate, MAX(vehicle_id) vehicle_id,
                COUNT(*) sold, SUM(boarded = 1) boarded, SUM(amount) amount, GROUP_CONCAT(DISTINCT boarding_point ORDER BY boarding_point SEPARATOR ", ") points')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->travel_date][] = ['programacion' => $r->programacion, 'time' => substr((string) $r->t, 0, 5), 'side' => self::side($r->origin),
                'origin' => $r->origin, 'destination' => $r->destination, 'plate' => $r->plate, 'vehicle_id' => $r->vehicle_id ? (int) $r->vehicle_id : null,
                'sold' => (int) $r->sold, 'boarded' => (int) $r->boarded, 'amount' => round((float) $r->amount, 2), 'points' => $r->points];
        }

        return $out;
    }

    private static function minutes(?string $hm): ?int
    {
        return $hm && preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    public static function week(int $cid, string $start): array
    {
        $monday = Carbon::parse($start)->startOfWeek(Carbon::MONDAY);
        $from = $monday->toDateString();
        $to = $monday->copy()->addDays(6)->toDateString();
        $today = now()->toDateString();
        $vehicles = TransportSchedule::vehicles($cid);
        $jelaf = self::jelaf($cid, $from, $to);
        $curve = self::curve($cid);
        $usual = self::usual($cid);
        $days = [];
        $all = [];   // for the bus checks: [vehicle_id => [[ts, side, day index, dep index]]]
        for ($i = 0; $i < 7; $i++) {
            $date = $monday->copy()->addDays($i);
            $day = $date->toDateString();
            $left = $day < $today ? null : (int) Carbon::parse($today)->diffInDays($date);
            $plan = self::planFor($cid, $day, $vehicles);
            $planDeps = $plan['departures'];
            $jDeps = $jelaf[$day] ?? [];
            // Match: same side, within 45 minutes, the same bus first.
            $pairs = [];
            foreach ($planDeps as $pi => $p) {
                foreach ($jDeps as $ji => $j) {
                    if ($p['side'] !== $j['side']) {
                        continue;
                    }
                    $diff = abs(self::minutes($p['time']) - self::minutes($j['time']));
                    if ($diff <= 45) {
                        $pairs[] = [$diff - ($p['vehicle_id'] && $p['vehicle_id'] === $j['vehicle_id'] ? 60 : 0), $pi, $ji];
                    }
                }
            }
            usort($pairs, fn ($a, $b) => $a[0] <=> $b[0]);
            $pUsed = $jUsed = [];
            $merged = [];
            foreach ($pairs as [, $pi, $ji]) {
                if (isset($pUsed[$pi]) || isset($jUsed[$ji])) {
                    continue;
                }
                $pUsed[$pi] = $jUsed[$ji] = true;
                $merged[] = [$planDeps[$pi], $jDeps[$ji]];
            }
            foreach ($planDeps as $pi => $p) {
                isset($pUsed[$pi]) || $merged[] = [$p, null];
            }
            foreach ($jDeps as $ji => $j) {
                isset($jUsed[$ji]) || $merged[] = [null, $j];
            }
            // A future day's Jelaf departures carry a stand-in bus until one is assigned (the same plate on several departures
            // of the day): it gives the seats of that kind of bus, not the bus.
            $standIn = [];
            if ($day > $today) {
                foreach (array_count_values(array_filter(array_column($jDeps, 'vehicle_id'))) as $jv => $n) {
                    $n > 1 && $standIn[$jv] = true;
                }
            }
            $deps = [];
            foreach ($merged as [$p, $j]) {
                if ($p && $p['removed'] && ! $j) {
                    continue;   // removed for the day and nothing sold on it
                }
                $jVid = $j && $j['vehicle_id'] && ! isset($standIn[$j['vehicle_id']]) ? $j['vehicle_id'] : null;
                $vid = ($p['changed'] ?? false) || ! $jVid ? ($p['vehicle_id'] ?? $jVid) : $jVid;
                $busFrom = ! $vid ? null : ($vid === $jVid && ! ($p['changed'] ?? false) ? 'jelaf'
                    : (($p['changed'] ?? false) || ($p['added'] ?? false) ? 'change' : (($plan['plan']['kind'] ?? '') === 'season' ? 'season' : 'base')));
                $v = $vid ? ($vehicles[$vid] ?? null) : null;
                $side = $p['side'] ?? $j['side'];
                $time = $p['time'] ?? $j['time'];
                $sold = $j['sold'] ?? 0;
                $seats = $v['seats'] ?? (($j['vehicle_id'] ?? null) ? ($vehicles[$j['vehicle_id']]['seats'] ?? null) : null);
                $forecast = $sold;
                if ($left !== null) {
                    $r = $curve[$side][min($left, 15)] ?? 0.5;
                    $forecast = (int) round($sold + (1 - $r) * self::usualFor($usual, $side, $date->isoWeekday(), intdiv((int) self::minutes($time), 60)));
                }
                $pct = $seats ? $forecast / $seats : null;
                $suggest = null;
                if ($left !== null && $pct !== null && ! ($p['removed'] ?? false)) {
                    $suggest = $pct >= self::HIGH ? 'reinforce' : ($pct < self::LOW ? 'low' : null);
                }
                $deps[] = [
                    'key' => $day.'|'.($p['slot_id'] ?? '').'|'.($p['change_id'] ?? '').'|'.($j['programacion'] ?? ''),
                    'side' => $side, 'time' => $time, 'terminal' => $p['terminal'] ?? null, 'tag' => $p['tag'] ?? null,
                    'vehicle_id' => $vid, 'bus_from' => $busFrom, 'bus' => $v['name'] ?? ($jVid ? ($j['plate'] ?? null) : null), 'plate' => $v['plate'] ?? ($jVid ? ($j['plate'] ?? null) : null), 'seats' => $seats,
                    'sold' => $sold, 'boarded' => $j['boarded'] ?? 0, 'amount' => $j['amount'] ?? 0, 'forecast' => $forecast, 'pct' => $pct !== null ? round($pct, 3) : null,
                    'suggest' => $suggest, 'source' => $p && $j ? 'both' : ($p ? 'plan' : 'jelaf'), 'programacion' => $j['programacion'] ?? null,
                    'route' => $j ? trim(($j['origin'] ?? '').' → '.($j['destination'] ?? '')) : null, 'points' => $j['points'] ?? null,
                    'plan_bus' => $p && $p['vehicle_id'] ? ($vehicles[$p['vehicle_id']]['name'] ?? null) : null,
                    'slot_id' => $p['slot_id'] ?? null, 'change_id' => $p['change_id'] ?? null, 'added' => (bool) ($p['added'] ?? false),
                    'changed' => (bool) ($p['changed'] ?? false), 'removed' => (bool) ($p['removed'] ?? false), 'note' => $p['note'] ?? null,
                    'boardings' => $p['boardings'] ?? [], 'alerts' => [],
                ];
            }
            usort($deps, fn ($a, $b) => [$a['side'] === 'lima' ? 0 : 1, self::minutes($a['time'])] <=> [$b['side'] === 'lima' ? 0 : 1, self::minutes($b['time'])]);
            foreach ($deps as $k => $d) {
                // Only a sure bus is checked: Jelaf's real one, a season's, a date change's -- the base timetable's bus is
                // its usual rotation, not where that bus is on a given date.
                if ($d['vehicle_id'] && ! $d['removed'] && $d['bus_from'] !== 'base') {
                    $all[$d['vehicle_id']][] = [strtotime($day.' '.$d['time']), $d['side'], $i, $k];
                }
            }
            $days[$i] = ['date' => $day, 'label' => self::DAY_NAMES[$date->isoWeekday()].' '.$date->format('d'), 'past' => $day < $today, 'today' => $day === $today,
                'days_left' => $left, 'plan' => $plan['plan'], 'departures' => $deps];
        }
        // Bus checks across the week.
        foreach ($all as $vid => $list) {
            usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
            for ($n = 1; $n < count($list); $n++) {
                [$t0, $s0, $d0, $k0] = $list[$n - 1];
                [$t1, $s1, $d1, $k1] = $list[$n];
                $h = ($t1 - $t0) / 3600;
                $msg = $s0 === $s1 && $h < 20 ? 'Sale dos veces del mismo lado en '.round($h).' h'
                    : ($s0 !== $s1 && $h < 10 ? 'Sale del otro extremo '.round($h).' h después: no alcanza a llegar' : null);
                if ($msg) {
                    $days[$d0]['departures'][$k0]['alerts'][] = $msg;
                    $days[$d1]['departures'][$k1]['alerts'][] = $msg;
                }
            }
        }
        $sum = ['reinforce' => 0, 'low' => 0, 'conflicts' => 0, 'plan_only' => 0, 'jelaf_only' => 0];
        foreach ($days as &$d) {
            $deps = collect($d['departures'])->where('removed', false);
            foreach (['lima', 'chiclayo'] as $side) {
                $s = $deps->where('side', $side);
                $d['sides'][$side] = ['departures' => $s->count(), 'sold' => $s->sum('sold'), 'forecast' => $s->sum('forecast'), 'seats' => $s->sum('seats'),
                    'buses' => $s->pluck('vehicle_id')->filter()->unique()->count()];
            }
            $d['sold'] = $deps->sum('sold');
            $d['forecast'] = $deps->sum('forecast');
            $d['seats'] = $deps->sum('seats');
            $sum['reinforce'] += $deps->where('suggest', 'reinforce')->count();
            $sum['low'] += $deps->where('suggest', 'low')->count();
            $sum['conflicts'] += $deps->filter(fn ($x) => $x['alerts'])->count();
            if (! $d['past']) {
                $sum['plan_only'] += $deps->where('source', 'plan')->count();
                $sum['jelaf_only'] += $deps->where('source', 'jelaf')->count();
            }
        }
        unset($d);
        $last = DB::table('transport_jelaf_runs')->where('company_id', $cid)->where('report', 'ahead')->where('status', 'ok')->orderByDesc('id')->value('finished_at');

        return ['from' => $from, 'to' => $to, 'today' => $today, 'days' => array_values($days), 'summary' => $sum,
            'thresholds' => ['high' => self::HIGH, 'low' => self::LOW], 'ahead_at' => $last,
            'vehicles' => array_values($vehicles), 'tags' => ['EJEC', 'EJEC VIP', 'PREM', 'MASTER', 'TRUX', 'R', 'OPC'],
            'terminals' => ['lima' => TransportSchedule::TERMINALS_LIMA, 'chiclayo' => TransportSchedule::TERMINALS_CIX]];
    }

    /** A date's change on top of the plan: add (reinforcement), remove, change (bus / time / tag); undo = delete it. */
    public static function change(int $cid, array $d, int $userId): int
    {
        $vehicles = TransportSchedule::vehicles($cid);
        abort_if(! empty($d['vehicle_id']) && ! isset($vehicles[(int) $d['vehicle_id']]), 422, 'Wrong bus.');
        $time = isset($d['time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string) $d['time'], $m) ? sprintf('%02d:%02d:00', $m[1], $m[2]) : null;
        if ($d['action'] === 'add') {
            abort_unless($time && in_array($d['side'] ?? '', ['lima', 'chiclayo'], true), 422, __('Give the side and the time.'));
        } else {
            abort_unless(! empty($d['slot_id']), 422, 'Which departure?');
            // One change per departure and date: a new one replaces it.
            DB::table('transport_schedule_changes')->where('company_id', $cid)->where('day', $d['day'])->where('slot_id', (int) $d['slot_id'])->delete();
        }

        return DB::table('transport_schedule_changes')->insertGetId([
            'company_id' => $cid, 'day' => $d['day'], 'action' => $d['action'], 'slot_id' => $d['action'] === 'add' ? null : (int) $d['slot_id'],
            'side' => $d['side'] ?? null, 'terminal' => isset($d['terminal']) ? mb_substr(strtoupper(trim((string) $d['terminal'])), 0, 40) : null,
            'time' => $time, 'tag' => isset($d['tag']) ? mb_substr(trim((string) $d['tag']), 0, 20) ?: null : null,
            'vehicle_id' => ! empty($d['vehicle_id']) ? (int) $d['vehicle_id'] : null, 'note' => isset($d['note']) ? mb_substr(trim((string) $d['note']), 0, 250) ?: null : null,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function undo(int $cid, int $id): void
    {
        DB::table('transport_schedule_changes')->where('company_id', $cid)->where('id', $id)->delete();
    }

    /** The week as an Excel grid like Operaciones' own: a column per day, the Lima side then the north side, one line per departure. */
    public static function export(int $cid, string $start): string
    {
        $w = self::week($cid, $start);
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $book->getActiveSheet()->setTitle('Programación');
        $sh->setCellValue('A1', 'Programación de buses '.Carbon::parse($w['from'])->format('d-m-Y').' al '.Carbon::parse($w['to'])->format('d-m-Y'));
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        foreach ($w['days'] as $i => $d) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sh->setCellValue($col.'3', mb_strtoupper($d['label']));
            $sh->getStyle($col.'3')->getFont()->setBold(true);
            $sh->getColumnDimension($col)->setWidth(30);
            $row = 4;
            foreach (['lima' => 'DESDE LIMA', 'chiclayo' => 'DESDE EL NORTE'] as $side => $title) {
                $sh->setCellValue($col.$row, $title);
                $sh->getStyle($col.$row)->getFont()->setBold(true)->getColor()->setRGB('F28C13');
                $row++;
                foreach ($d['departures'] as $dep) {
                    if ($dep['side'] !== $side || $dep['removed']) {
                        continue;
                    }
                    $t = Carbon::createFromFormat('H:i', $dep['time'])->format('g:i A');
                    $sh->setCellValue($col.$row++, trim("$t  ".($dep['terminal'] ?? '').'  '.($dep['bus'] ?? '').'  '.($dep['tag'] ?? '').'  '.$dep['sold'].($dep['seats'] ? '/'.$dep['seats'] : '')));
                }
                $row++;
            }
        }
        $path = storage_path('app/programacion-'.$w['from'].'.xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        return $path;
    }
}
