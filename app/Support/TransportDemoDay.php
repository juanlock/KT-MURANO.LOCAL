<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A transport demo that is alive every day (user's request, 2026-11-02, for the
 * presentations): the demo made by TransportModule (30 days of trips back, a week
 * ahead) would age -- in a week no trip ahead, the board empty. For a company with
 * transport demo trips (demo_records, module transport):
 *  - days(): every day up to a week ahead gets its trips, copied from the same
 *    weekday-pattern 6 days before (the crews rotate every 2 and 3 days, the buses
 *    every 2 trips, so a 6-day copy keeps nobody / no bus on two trips at once);
 *  - statuses(): trips that already arrived = completed (now and then cancelled),
 *    on the road = in progress;
 *  - trim(): demo trips older than 30 days removed (crew included);
 *  - maintenance(): the demo jobs keep their place around today (one in the
 *    workshop since yesterday, one scheduled next week);
 *  - complaints(): one new demo complaint a day in the demo complaints book.
 * Only demo rows are touched; the company's own trips never. Safe to run often
 * (`php artisan transport:demo-day`, hourly).
 */
class TransportDemoDay
{
    private const MODULE = 'transport';

    public static function run(int $companyId): array
    {
        return [
            'days' => self::days($companyId), 'statuses' => self::statuses($companyId), 'trimmed' => self::trim($companyId),
            'maintenance' => self::maintenance($companyId), 'complaints' => self::complaints($companyId),
        ];
    }

    /** Companies with transport demo trips. */
    public static function companies(): array
    {
        return DB::table('demo_records')->where('module', self::MODULE)->where('record_type', 'transport_trips')
            ->distinct()->pluck('company_id')->map(fn ($v) => (int) $v)->all();
    }

    private static function ids(int $cid, string $table): array
    {
        return DB::table('demo_records')->where('company_id', $cid)->where('module', self::MODULE)->where('record_type', $table)
            ->pluck('record_id')->map(fn ($v) => (int) $v)->all();
    }

    private static function tag(int $cid, string $table, int $id): void
    {
        DB::table('demo_records')->insert(['company_id' => $cid, 'module' => self::MODULE, 'record_type' => $table, 'record_id' => $id,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** The days after the last demo day, up to a week ahead, copied from 6 days before. */
    public static function days(int $cid): int
    {
        $ids = self::ids($cid, 'transport_trips');
        if (! $ids) {
            return 0;
        }
        $last = DB::table('transport_trips')->whereIn('id', $ids)->whereNull('deleted_at')->max('departure_at');
        if (! $last) {
            return 0;
        }
        $made = 0;
        $end = now()->startOfDay()->addDays(7);
        for ($day = \Carbon\Carbon::parse($last)->startOfDay()->addDay(); $day->lte($end); $day->addDay()) {
            $source = $day->copy()->subDays(6);
            $trips = DB::table('transport_trips')->whereIn('id', self::ids($cid, 'transport_trips'))->whereNull('deleted_at')
                ->whereBetween('departure_at', [$source->copy()->startOfDay(), $source->copy()->endOfDay()])->orderBy('departure_at')->get();
            if ($trips->isEmpty()) {
                break; // nothing to copy from: the demo was reset or never had that day
            }
            DB::transaction(function () use ($trips, $cid, &$made) {
                foreach ($trips as $t) {
                    $dep = \Carbon\Carbon::parse($t->departure_at)->addDays(6);
                    $arr = $t->arrival_at ? \Carbon\Carbon::parse($t->arrival_at)->addDays(6) : null;
                    $id = DB::table('transport_trips')->insertGetId([
                        'company_id' => $cid, 'route_id' => $t->route_id, 'vehicle_id' => $t->vehicle_id, 'departure_at' => $dep, 'arrival_at' => $arr,
                        'status' => self::statusAt($dep, $arr, 0), 'notes' => $t->notes, 'created_by' => $t->created_by,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    self::tag($cid, 'transport_trips', $id);
                    foreach (DB::table('transport_trip_crew')->where('trip_id', $t->id)->get() as $c) {
                        $row = (array) $c;
                        unset($row['id']);
                        $row['trip_id'] = $id;
                        DB::table('transport_trip_crew')->insert($row);
                    }
                    $made++;
                }
            });
        }

        return $made;
    }

    /** What a trip is at this moment; one in 17 arrived trips was cancelled (like the demo). */
    private static function statusAt($dep, $arr, int $id): string
    {
        if ($arr && $arr->isPast()) {
            return $id % 17 === 5 ? 'cancelled' : 'completed';
        }

        return $dep->isPast() ? 'in_progress' : 'scheduled';
    }

    /** Demo trips follow the clock: on the road = in progress, arrived = completed. */
    public static function statuses(int $cid): int
    {
        $ids = self::ids($cid, 'transport_trips');
        if (! $ids) {
            return 0;
        }
        $n = 0;
        foreach (DB::table('transport_trips')->whereIn('id', $ids)->whereNull('deleted_at')->whereIn('status', ['scheduled', 'in_progress'])
            ->where('departure_at', '<=', now())->get(['id', 'departure_at', 'arrival_at', 'status']) as $t) {
            $status = self::statusAt(\Carbon\Carbon::parse($t->departure_at), $t->arrival_at ? \Carbon\Carbon::parse($t->arrival_at) : null, (int) $t->id);
            if ($status !== $t->status) {
                DB::table('transport_trips')->where('id', $t->id)->update(['status' => $status, 'updated_at' => now()]);
                $n++;
            }
        }

        return $n;
    }

    /** Demo trips older than 30 days go away (with their crew and tags). */
    public static function trim(int $cid): int
    {
        $ids = self::ids($cid, 'transport_trips');
        if (! $ids) {
            return 0;
        }
        $old = DB::table('transport_trips')->whereIn('id', $ids)->where('departure_at', '<', now()->startOfDay()->subDays(30))->pluck('id')->all();
        if (! $old) {
            return 0;
        }
        DB::transaction(function () use ($old, $cid) {
            DB::table('transport_trip_crew')->whereIn('trip_id', $old)->delete();
            DB::table('transport_trips')->whereIn('id', $old)->delete();
            DB::table('demo_records')->where('company_id', $cid)->where('module', self::MODULE)->where('record_type', 'transport_trips')->whereIn('record_id', $old)->delete();
        });

        return count($old);
    }

    /** The demo's workshop job stays "since yesterday", the scheduled one "next week". */
    public static function maintenance(int $cid): int
    {
        $ids = self::ids($cid, 'vehicle_maintenances');
        if (! $ids) {
            return 0;
        }
        $n = DB::table('vehicle_maintenances')->whereIn('id', $ids)->where('status', 'in_progress')
            ->whereDate('service_date', '<', now()->subDay()->toDateString())->update(['service_date' => now()->subDay()->toDateString(), 'updated_at' => now()]);
        $n += DB::table('vehicle_maintenances')->whereIn('id', $ids)->where('status', 'scheduled')
            ->whereDate('service_date', '<', now()->toDateString())->update(['service_date' => now()->addDays(6)->toDateString(), 'updated_at' => now()]);

        return $n;
    }

    /** One new complaint a day in the demo complaints book (a copy of an older one). */
    public static function complaints(int $cid): int
    {
        $forms = self::ids($cid, 'forms');
        $subs = self::ids($cid, 'form_submissions');
        if (! $forms || ! $subs) {
            return 0;
        }
        $today = DB::table('form_submissions')->whereIn('id', $subs)->whereIn('form_id', $forms)->whereDate('created_at', now()->toDateString())->exists();
        $model = DB::table('form_submissions')->whereIn('id', $subs)->whereIn('form_id', $forms)->inRandomOrder()->first();
        if ($today || ! $model) {
            return 0;
        }
        $at = now()->subMinutes(random_int(5, max(6, now()->hour * 60 + now()->minute)));
        DB::transaction(function () use ($model, $cid, $at) {
            $number = (int) DB::table('forms')->where('id', $model->form_id)->lockForUpdate()->value('next_number') ?: 1;
            $id = DB::table('form_submissions')->insertGetId(['form_id' => $model->form_id, 'number' => $number, 'data' => $model->data,
                'is_read' => 0, 'ip' => '127.0.0.1', 'created_at' => $at, 'updated_at' => $at]);
            DB::table('forms')->where('id', $model->form_id)->update(['next_number' => $number + 1]);
            self::tag($cid, 'form_submissions', $id);
        });

        return 1;
    }
}
