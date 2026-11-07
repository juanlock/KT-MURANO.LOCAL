<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A bus's GPS trail is kept for 30 days (2026-11-06): it serves the map and the check that a pilot is stopped.
 * What stays for good: the control points' times, photos and reports.
 */
class TransportPrunePositions extends Command
{
    protected $signature = 'transport:prune-positions {--days=30}';

    protected $description = 'Delete GPS positions of buses older than the given days';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) $this->option('days')));
        $total = 0;
        do {
            $n = DB::table('transport_vehicle_positions')->where('recorded_at', '<', $before)->limit(5000)->delete();
            $total += $n;
        } while ($n === 5000);
        $this->info("Deleted {$total} position(s) before {$before}.");

        return self::SUCCESS;
    }
}
