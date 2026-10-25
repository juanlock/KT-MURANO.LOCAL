<?php

namespace App\Console\Commands;

use App\Support\HospitalDemoDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily (2026-10-25): every hospital company with demo data gets today's
 * roster (copied from the last day that has one), its patients shared out
 * among the doctors and nurses, and the example stays when missing -- so a
 * demo always has something to work with today.
 */
class HospitalDemoDayCommand extends Command
{
    protected $signature = 'hospital:demo-day {--company= : Only this company id}';

    protected $description = 'Keep hospital demos alive: today\'s roster, assignments and example stays';

    public function handle(): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')]
            : DB::table('demo_records')->where('module', 'hospital')->distinct()->pluck('company_id')->map(fn ($v) => (int) $v)->all();
        foreach ($ids as $cid) {
            $this->line("company {$cid}: ".json_encode(HospitalDemoDay::run($cid)));
        }

        return self::SUCCESS;
    }
}
