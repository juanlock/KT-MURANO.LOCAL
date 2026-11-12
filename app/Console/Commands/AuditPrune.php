<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The change history (audit_logs) keeps one year (2026-11-12); older rows are removed in small batches. */
class AuditPrune extends Command
{
    protected $signature = 'audit:prune {--days=365}';

    protected $description = 'Remove change-history rows older than the given days (default 365)';

    public function handle(): int
    {
        if (! Schema::hasTable('audit_logs')) {
            return self::SUCCESS;
        }
        $before = now()->subDays(max(30, (int) $this->option('days')));
        $total = 0;
        do {
            $n = DB::table('audit_logs')->where('created_at', '<', $before)->limit(5000)->delete();
            $total += $n;
        } while ($n > 0);
        $this->info("Removed {$total} change-history row(s) older than {$before->toDateString()}.");

        return self::SUCCESS;
    }
}
