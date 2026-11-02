<?php

namespace App\Console\Commands;

use App\Support\TransportDemoDay;
use Illuminate\Console\Command;

/**
 * Hourly (2026-11-02): every company with transport demo trips keeps a week of
 * trips ahead, its trips' status following the clock, 30 days of history, the
 * demo maintenance around today and a new demo complaint a day.
 */
class TransportDemoDayCommand extends Command
{
    protected $signature = 'transport:demo-day {--company= : Only this company id}';

    protected $description = 'Keep transport demos alive: trips up to a week ahead, statuses, maintenance, complaints';

    public function handle(): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')] : TransportDemoDay::companies();
        foreach ($ids as $cid) {
            $this->line("company {$cid}: ".json_encode(TransportDemoDay::run($cid)));
        }

        return self::SUCCESS;
    }
}
