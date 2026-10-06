<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Holidays: the US federal holidays of this year and next (HRM -> Holidays),
 * computed per year (e.g. Thanksgiving = 4th Thursday of November), plus the
 * day after Thanksgiving and Christmas Eve, which most small businesses close.
 * A date the company already has a holiday on is skipped (no duplicates).
 */
class HolidaysModule extends DemoModule
{
    public function key(): string
    {
        return 'holidays';
    }

    public function label(): string
    {
        return 'Holidays (US federal holidays of this year and next)';
    }

    public function dataTables(): array
    {
        return ['holidays'];
    }

    /** [title, start, end, description] of a year's holidays. */
    public static function forYear(int $y): array
    {
        $nth = fn (int $n, int $dow, int $month) => Carbon::create($y, $month, 1)->nthOfMonth($n, $dow);
        $lastMon = Carbon::create($y, 5, 1)->lastOfMonth(Carbon::MONDAY);
        $thanksgiving = $nth(4, Carbon::THURSDAY, 11);
        $rows = [
            ["New Year's Day", Carbon::create($y, 1, 1), 'Federal holiday'],
            ['Martin Luther King Jr. Day', $nth(3, Carbon::MONDAY, 1), 'Federal holiday (3rd Monday of January)'],
            ["Presidents' Day", $nth(3, Carbon::MONDAY, 2), "Washington's Birthday (3rd Monday of February)"],
            ['Memorial Day', $lastMon, 'Federal holiday (last Monday of May)'],
            ['Juneteenth', Carbon::create($y, 6, 19), 'Federal holiday'],
            ['Independence Day', Carbon::create($y, 7, 4), 'Federal holiday'],
            ['Labor Day', $nth(1, Carbon::MONDAY, 9), 'Federal holiday (1st Monday of September)'],
            ['Columbus Day', $nth(2, Carbon::MONDAY, 10), 'Federal holiday (2nd Monday of October)'],
            ['Veterans Day', Carbon::create($y, 11, 11), 'Federal holiday'],
            ['Thanksgiving Day', $thanksgiving, 'Federal holiday (4th Thursday of November)'],
            ['Day after Thanksgiving', $thanksgiving->copy()->addDay(), 'Common business closure'],
            ['Christmas Eve', Carbon::create($y, 12, 24), 'Common business closure'],
            ['Christmas Day', Carbon::create($y, 12, 25), 'Federal holiday'],
        ];

        return array_map(fn ($r) => [$r[0], $r[1]->toDateString(), $r[1]->toDateString(), $r[2]], $rows);
    }

    public function generate(DemoContext $ctx): array
    {
        $year = (int) date('Y');
        $made = 0;
        foreach ([$year, $year + 1] as $y) {
            foreach (self::forYear($y) as [$title, $start, $end, $desc]) {
                $exists = DB::table('holidays')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
                    ->where('start_date', '<=', $start)->where('end_date', '>=', $start)->exists();
                if ($exists) {
                    continue;
                }
                $ctx->insert('holidays', ['title' => $title, 'start_date' => $start, 'end_date' => $end, 'description' => $desc]);
                $made++;
            }
        }

        return ['Holidays' => $made];
    }
}
