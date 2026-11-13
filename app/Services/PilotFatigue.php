<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pilots' fatigue (user's rules, 2026-11-13): adding up a year of hours says nothing -- tiredness is about short windows.
 * A pilot is warned when, at the wheel:
 *  - continuous: more than 12 h in a row (trips less than 1 h apart are one stretch: a stop at a terminal is no rest);
 *  - h48: more than 30 h in any 48 hours;
 *  - h72: more than 46 h in any 72 hours;
 *  - swap_missing: a long trip (over 6 h) where the pilot never handed the wheel to the copilot;
 *  - overlap: on two buses at the same time (a planning error).
 * Who is at the wheel: on a short trip the first pilot of the crew all the way; on a long one the first until the swap
 * (transport_trip_swaps, confirmed from "Mi viaje" or the control board) and the copilot after it. A long trip with no
 * swap on record counts the first pilot all the way -- for the company's trips from SWAP_FROM on (before it nobody could
 * confirm a swap: they count as swapped at half way) and for every demo trip. The limits are the company's
 * (settings.transport_fatigue), these are the defaults.
 */
class PilotFatigue
{
    public const DEFAULTS = ['continuous' => 12, 'h48' => 30, 'h72' => 46, 'swap_after' => 6, 'gap_min' => 60, 'swap_late_min' => 30];

    /** The first day a swap could be confirmed in Murano. */
    public const SWAP_FROM = '2026-11-14';

    public static function rules(int $cid): array
    {
        $raw = Schema::hasColumn('settings', 'transport_fatigue')
            ? DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('transport_fatigue') : null;
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $out = self::DEFAULTS;
        foreach ($out as $k => $v) {
            if (isset($saved[$k]) && is_numeric($saved[$k]) && $saved[$k] > 0) {
                $out[$k] = $saved[$k] + 0;
            }
        }

        return $out;
    }

    public static function saveRules(int $cid, array $rules): array
    {
        $clean = [];
        foreach (self::DEFAULTS as $k => $v) {
            $clean[$k] = isset($rules[$k]) && is_numeric($rules[$k]) && $rules[$k] > 0 ? $rules[$k] + 0 : $v;
        }
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['transport_fatigue' => json_encode($clean), 'updated_at' => now()]);

        return $clean;
    }

    /**
     * Each pilot's hours and alerts in [from, to] (dates). The 3 days before `from` are read too, so a window that
     * starts before the period is measured whole. $employeeId = only that pilot (with the trips behind each alert).
     */
    public static function analyze(int $cid, string $from, string $to, ?int $employeeId = null): array
    {
        $rules = self::rules($cid);
        $now = now();
        $pStart = Carbon::parse($from)->startOfDay();
        $pEnd = min(Carbon::parse($to)->endOfDay(), $now);
        $readFrom = $pStart->copy()->subHours(72 + (int) $rules['continuous'] + 24);

        $trips = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->leftJoin('transport_locations as o', 'o.id', '=', 'r.origin_id')->leftJoin('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')->whereIn('t.status', ['completed', 'in_progress'])
            ->where('t.departure_at', '>=', $readFrom)->where('t.departure_at', '<=', $pEnd)->whereNotNull('t.arrival_at')
            ->when($employeeId, fn ($q) => $q->whereExists(fn ($e) => $e->from('transport_trip_crew as c')->whereColumn('c.trip_id', 't.id')->where('c.employee_id', $employeeId)->where('c.role', 'pilot')))
            ->get(['t.id', 't.departure_at', 't.arrival_at', 't.status', 'o.name as origin', 'd.name as destination', 'v.name as bus'])->keyBy('id');
        if ($trips->isEmpty()) {
            return ['rules' => $rules, 'pilots' => []];
        }
        $ids = $trips->keys()->all();
        $crew = DB::table('transport_trip_crew')->whereIn('trip_id', $ids)->where('role', 'pilot')->orderBy('id')->get(['trip_id', 'employee_id'])->groupBy('trip_id');
        $swaps = Schema::hasTable('transport_trip_swaps')
            ? DB::table('transport_trip_swaps')->whereIn('trip_id', $ids)->orderBy('id')->get(['trip_id', 'swapped_at'])->keyBy('trip_id') : collect();
        $demo = DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'transport_trips')->whereIn('record_id', $ids)->pluck('record_id')->flip();

        // Every pilot's stretches at the wheel and on board.
        $drive = [];
        $board = [];
        $alerts = [];
        $tripInfo = fn ($t) => ['id' => (int) $t->id, 'departure_at' => (string) $t->departure_at, 'arrival_at' => (string) $t->arrival_at,
            'route' => trim(($t->origin ?? '').' → '.($t->destination ?? '')), 'bus' => $t->bus];
        foreach ($trips as $t) {
            $dep = Carbon::parse($t->departure_at);
            $end = min(Carbon::parse($t->arrival_at), $now);
            if ($end->lte($dep)) {
                continue;
            }
            $pilots = collect($crew[$t->id] ?? [])->pluck('employee_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
            if (! $pilots) {
                continue;
            }
            $hours = $dep->diffInMinutes(Carbon::parse($t->arrival_at)) / 60;
            foreach ($pilots as $p) {
                $board[$p][] = [$dep->timestamp, $end->timestamp, (int) $t->id];
            }
            $long = $hours > $rules['swap_after'] && count($pilots) >= 2;
            if (! $long) {
                $drive[$pilots[0]][] = [$dep->timestamp, $end->timestamp, (int) $t->id];

                continue;
            }
            $swap = $swaps[$t->id] ?? null;
            $required = isset($demo[$t->id]) || $dep->toDateString() >= self::SWAP_FROM;
            if ($swap) {
                $at = Carbon::parse($swap->swapped_at);
                $at = $at->lt($dep) ? $dep : ($at->gt($end) ? $end : $at);
            } elseif (! $required) {
                $at = $dep->copy()->addMinutes((int) round($hours * 30)); // before swaps were recorded: half way
            } else {
                $due = $dep->copy()->addMinutes((int) round($hours * 30) + (int) $rules['swap_late_min']);
                if ($now->lt($due)) {
                    $at = $end; // still before the swap: the first pilot is driving so far
                } else {
                    $at = null;
                    $alerts[$pilots[0]][] = ['rule' => 'swap_missing', 'from' => (string) $t->departure_at, 'to' => $end->format('Y-m-d H:i:s'),
                        'hours' => round($hours, 1), 'limit' => $rules['swap_after'], 'trips' => [(int) $t->id], 'with' => $pilots[1]];
                }
            }
            if ($at === null) {
                $drive[$pilots[0]][] = [$dep->timestamp, $end->timestamp, (int) $t->id];
            } else {
                if ($at->gt($dep)) {
                    $drive[$pilots[0]][] = [$dep->timestamp, $at->timestamp, (int) $t->id];
                }
                if ($end->gt($at)) {
                    $drive[$pilots[1]][] = [$at->timestamp, $end->timestamp, (int) $t->id];
                }
            }
        }

        $ps = $pStart->timestamp;
        $pe = $pEnd->timestamp;
        $clip = fn (array $segs) => array_sum(array_map(fn ($s) => max(0, min($s[1], $pe) - max($s[0], $ps)), $segs)) / 3600;
        $everyone = array_unique(array_merge(array_keys($board), array_keys($drive)));
        if ($employeeId) {
            $everyone = array_values(array_intersect($everyone, [$employeeId]));
        }
        $out = [];
        foreach ($everyone as $p) {
            $segs = $drive[$p] ?? [];
            usort($segs, fn ($a, $b) => $a[0] <=> $b[0]);
            $list = $alerts[$p] ?? [];

            // On two buses at once.
            $boardSegs = $board[$p] ?? [];
            usort($boardSegs, fn ($a, $b) => $a[0] <=> $b[0]);
            $reach = null;
            foreach ($boardSegs as $s) {
                if ($reach && $s[0] < $reach[1] && $s[2] !== $reach[2]) {
                    $list[] = ['rule' => 'overlap', 'from' => date('Y-m-d H:i:s', $s[0]), 'to' => date('Y-m-d H:i:s', min($s[1], $reach[1])),
                        'hours' => round((min($s[1], $reach[1]) - $s[0]) / 3600, 1), 'limit' => 0, 'trips' => [$reach[2], $s[2]]];
                }
                if (! $reach || $s[1] > $reach[1]) {
                    $reach = $s;
                }
            }
            // The wheel: overlapping stretches merged, so an hour is never counted twice.
            $merged = [];
            foreach ($segs as $s) {
                $last = count($merged) - 1;
                if ($last >= 0 && $s[0] <= $merged[$last][1]) {
                    $merged[$last][1] = max($merged[$last][1], $s[1]);
                    $merged[$last][2][] = $s[2];
                } else {
                    $merged[] = [$s[0], $s[1], [$s[2]]];
                }
            }

            // Continuous: stretches less than gap_min apart are one.
            $gap = (int) $rules['gap_min'] * 60;
            $run = null;
            $flush = function ($run) use (&$list, $rules) {
                if ($run && $run['secs'] / 3600 > $rules['continuous']) {
                    $list[] = ['rule' => 'continuous', 'from' => date('Y-m-d H:i:s', $run['from']), 'to' => date('Y-m-d H:i:s', $run['to']),
                        'hours' => round($run['secs'] / 3600, 1), 'limit' => $rules['continuous'], 'trips' => array_values(array_unique($run['trips']))];
                }
            };
            foreach ($merged as $m) {
                if ($run && $m[0] - $run['to'] < $gap) {
                    $run['to'] = $m[1];
                    $run['secs'] += $m[1] - $m[0];
                    $run['trips'] = array_merge($run['trips'], $m[2]);
                } else {
                    $flush($run);
                    $run = ['from' => $m[0], 'to' => $m[1], 'secs' => $m[1] - $m[0], 'trips' => $m[2]];
                }
            }
            $flush($run);

            // Rolling windows: measured at the end of every stretch; breaches in a row are one alert.
            foreach (['h48' => 48, 'h72' => 72] as $rule => $span) {
                $episode = null;
                foreach ($merged as $i => $m) {
                    $wEnd = $m[1];
                    $wStart = $wEnd - $span * 3600;
                    $secs = 0;
                    $in = [];
                    for ($j = $i; $j >= 0 && $merged[$j][1] > $wStart; $j--) {
                        $secs += $merged[$j][1] - max($merged[$j][0], $wStart);
                        $in = array_merge($in, $merged[$j][2]);
                    }
                    $hours = $secs / 3600;
                    if ($hours > $rules[$rule]) {
                        if ($episode && $wStart < $episode['to']) {
                            $episode['to'] = $wEnd;
                            $episode['hours'] = max($episode['hours'], $hours);
                            $episode['trips'] = array_merge($episode['trips'], $in);
                        } else {
                            if ($episode) {
                                $list[] = $episode;
                            }
                            $episode = ['rule' => $rule, 'from' => $wStart, 'to' => $wEnd, 'hours' => $hours, 'limit' => $rules[$rule], 'trips' => $in];
                        }
                    }
                }
                if ($episode) {
                    $list[] = $episode;
                }
            }
            foreach ($list as &$a) {
                foreach (['from', 'to'] as $k) {
                    if (is_int($a[$k])) {
                        $a[$k] = date('Y-m-d H:i:s', $a[$k]);
                    }
                }
                $a['hours'] = round($a['hours'], 1);
                $a['trips'] = array_values(array_unique($a['trips']));
                sort($a['trips']);
            }
            unset($a);
            // Only the alerts that end inside the period.
            $list = array_values(array_filter($list, fn ($a) => strtotime($a['to']) >= $ps && strtotime($a['to']) <= $pe));
            usort($list, fn ($a, $b) => strcmp($b['to'], $a['to']));
            $onPeriod = array_filter($boardSegs, fn ($s) => $s[1] > $ps && $s[0] < $pe);
            if (! $onPeriod && ! $list) {
                continue;
            }
            $out[$p] = [
                'id' => $p, 'driving' => round($clip(array_map(fn ($m) => [$m[0], $m[1]], $merged)), 1),
                'onboard' => round($clip(self::mergeBoard($boardSegs)), 1), 'trips' => count(array_unique(array_column($onPeriod, 2))),
                'alerts' => count($list), 'by_rule' => array_count_values(array_column($list, 'rule')),
                'list' => $employeeId ? array_map(function ($a) use ($trips, $tripInfo) {
                    $a['trips'] = array_values(array_filter(array_map(fn ($id) => isset($trips[$id]) ? $tripInfo($trips[$id]) : null, $a['trips'])));

                    return $a;
                }, $list) : null,
            ];
        }
        $names = DB::table('employees')->whereIn('id', array_merge(array_keys($out), collect($alerts)->flatten(1)->pluck('with')->filter()->all()) ?: [0])
            ->get(['id', 'firstname', 'lastname'])->mapWithKeys(fn ($e) => [(int) $e->id => trim($e->firstname.' '.$e->lastname)]);
        foreach ($out as &$o) {
            $o['name'] = $names[$o['id']] ?? '#'.$o['id'];
            if ($o['list']) {
                foreach ($o['list'] as &$a) {
                    if (isset($a['with'])) {
                        $a['with_name'] = $names[$a['with']] ?? null;
                    }
                }
                unset($a);
            }
        }
        unset($o);
        $pilots = array_values($out);
        usort($pilots, fn ($a, $b) => [$b['alerts'], $b['driving']] <=> [$a['alerts'], $a['driving']]);

        return ['rules' => $rules, 'pilots' => $pilots];
    }

    private static function mergeBoard(array $segs): array
    {
        $m = [];
        foreach ($segs as $s) {
            $l = count($m) - 1;
            if ($l >= 0 && $s[0] <= $m[$l][1]) {
                $m[$l][1] = max($m[$l][1], $s[1]);
            } else {
                $m[] = [$s[0], $s[1]];
            }
        }

        return $m;
    }

    /**
     * The pilot swap of one trip (for "Mi viaje" and the control board): null on a short trip or with one pilot.
     * state: upcoming (more than 30 min ahead), due (from 30 min before to swap_late_min after), late (past that, trip on
     * the road), missed (trip over without it), done.
     */
    public static function swapFor(object $trip, array $pilotNames, ?object $swap, array $rules): ?array
    {
        if (count($pilotNames) < 2 || ! $trip->arrival_at) {
            return null;
        }
        $dep = Carbon::parse($trip->departure_at);
        $minutes = $dep->diffInMinutes(Carbon::parse($trip->arrival_at));
        if ($minutes / 60 <= $rules['swap_after']) {
            return null;
        }
        $due = $dep->copy()->addMinutes((int) round($minutes / 2));
        $now = now();
        $state = 'upcoming';
        if ($swap) {
            $state = 'done';
        } elseif (in_array($trip->status, ['completed'], true)) {
            $state = 'missed';
        } elseif ($trip->status === 'in_progress' || $now->gte($dep)) {
            $state = $now->lt($due->copy()->subMinutes(30)) ? 'upcoming' : ($now->lte($due->copy()->addMinutes((int) $rules['swap_late_min'])) ? 'due' : 'late');
        }

        return ['due_at' => $due->format('Y-m-d H:i:s'), 'from' => $pilotNames[0], 'to' => $pilotNames[1], 'state' => $state,
            'done_at' => $swap ? (string) $swap->swapped_at : null, 'late_min' => (int) $rules['swap_late_min']];
    }
}
