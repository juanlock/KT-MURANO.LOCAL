<?php

namespace App\Console\Commands;

use App\Support\DemoDaily;
use App\Support\TransportDemoDay;
use Illuminate\Console\Command;

/**
 * Every 10 minutes (user's request, 2026-11-18): the GPS trail of the demo buses on the road, for the companies with
 * "Update the demo every day" on that have a transport demo (TransportDemoDay::gps). Only demo trips are touched.
 */
class TransportDemoGps extends Command
{
    protected $signature = 'transport:demo-gps {--company= : Only this company id}';

    protected $description = 'GPS positions of the demo buses on the road (transport demo)';

    public function handle(): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')]
            : array_values(array_intersect(DemoDaily::companies(), TransportDemoDay::companies()));
        foreach ($ids as $cid) {
            $this->line("company {$cid}: ".TransportDemoDay::gps($cid).' positions');
        }

        return self::SUCCESS;
    }
}
