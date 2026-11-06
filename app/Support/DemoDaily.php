<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * "Update the demo every day" (user's rule, 2026-11-02): a company with
 * companies.demo_daily on (Settings -> Demo Data) gets its demo kept current --
 * hospital (today's roster, assignments, example stays), school (the daily
 * register up to yesterday, exams under way) and transport (a week of trips
 * ahead, statuses on the clock, maintenance around today, a complaint a day) --
 * for each of those modules it has demo data in. `php artisan demo:daily`, hourly;
 * every refresher is safe to run again.
 */
class DemoDaily
{
    /** demo module key => refresher (static run(int $companyId): array). */
    public const REFRESHERS = [
        'hospital' => HospitalDemoDay::class,
        'school' => SchoolDemoDay::class,
        'transport' => TransportDemoDay::class,
    ];

    /** Companies with the switch on. */
    public static function companies(): array
    {
        return DB::table('companies')->where('demo_daily', 1)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public static function run(int $companyId): array
    {
        $out = [];
        foreach (self::REFRESHERS as $module => $class) {
            if (! DB::table('demo_records')->where('company_id', $companyId)->where('module', $module)->exists()) {
                continue;
            }
            try {
                $out[$module] = $class::run($companyId);
            } catch (\Throwable $e) {
                report($e); // one module failing never stops the others
                $out[$module] = 'failed: '.$e->getMessage();
            }
        }

        return $out;
    }
}
