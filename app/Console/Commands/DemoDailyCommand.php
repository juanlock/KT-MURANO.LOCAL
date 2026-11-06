<?php

namespace App\Console\Commands;

use App\Support\DemoDaily;
use Illuminate\Console\Command;

/**
 * Hourly (2026-11-02): every company with "Update the demo every day" on
 * (companies.demo_daily) gets its hospital / school / transport demo kept current.
 */
class DemoDailyCommand extends Command
{
    protected $signature = 'demo:daily {--company= : Only this company id (even with the switch off)}';

    protected $description = 'Keep the demos of the companies with "Update the demo every day" current';

    public function handle(): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')] : DemoDaily::companies();
        foreach ($ids as $cid) {
            $this->line("company {$cid}: ".json_encode(DemoDaily::run($cid)));
        }

        return self::SUCCESS;
    }
}
