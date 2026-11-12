<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change history (user's rule, 2026-11-12: nothing is deleted): audit_logs keeps the last 6 months; older rows move to
 * audit_logs_archive (same structure, same ids), in batches, each batch in one transaction (copy + remove together).
 * Runs every night, so the main table always holds about 6 months.
 */
class AuditArchive extends Command
{
    protected $signature = 'audit:archive {--months=6}';

    protected $description = 'Move change-history rows older than N months (default 6) to audit_logs_archive';

    public function handle(): int
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasTable('audit_logs_archive')) {
            return self::SUCCESS;
        }
        $before = now()->subMonths(max(1, (int) $this->option('months')))->startOfDay();
        $moved = 0;
        while (true) {
            $ids = DB::table('audit_logs')->where('created_at', '<', $before)->orderBy('id')->limit(2000)->pluck('id')->all();
            if (! $ids) {
                break;
            }
            DB::transaction(function () use ($ids) {
                // INSERT IGNORE: a batch interrupted after its copy is simply removed on the next run.
                $list = implode(',', array_map('intval', $ids));
                DB::statement("INSERT IGNORE INTO `audit_logs_archive` SELECT * FROM `audit_logs` WHERE `id` IN ($list)");
                DB::table('audit_logs')->whereIn('id', $ids)->delete();
            });
            $moved += count($ids);
        }
        $this->info("Moved {$moved} change-history row(s) older than {$before->toDateString()} to the archive.");

        return self::SUCCESS;
    }
}
