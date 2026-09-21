<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "No-cron" mode: run queued WooCommerce batches inside the HTTP progress poll,
 * so a manual sync started from the settings page finishes without Supervisor or
 * a `schedule:run` cron. The user just has to keep the page open.
 *
 * It drains for a time budget rather than a single batch: batches re-dispatch
 * themselves one slice at a time, so one-per-poll capped a manual sync at a
 * single batch per polling interval (10s for products, 5s for stock).
 */
class InlineQueueRunner
{
    /**
     * Work queued WooCommerce batches until the run finishes, the queue empties,
     * or the time budget runs out.
     *
     * @param  callable():bool  $runFinished  True once the caller's own sync is done.
     *                                        The queue is shared, so without this the
     *                                        poll would keep draining unrelated runs
     *                                        and answer with a stale progress payload.
     */
    public static function drain(callable $runFinished): void
    {
        try {
            $budget = SyncOptions::int('poll_tick_budget_seconds');
            $budget = max(5, min(300, $budget));

            // The request has to outlive the budget plus whatever the final batch needs.
            $limit = SyncOptions::int('poll_tick_max_seconds');
            $limit = max(30, min(1800, $limit));
            $limit = max($limit, $budget + 60);
            @ini_set('max_execution_time', (string) $limit);
            if (function_exists('set_time_limit')) {
                @set_time_limit($limit);
            }

            if (! Schema::hasTable('jobs')) {
                return;
            }
            if (! self::hasQueuedBatch()) {
                return;
            }

            // One inline drainer at a time; concurrent polls return immediately.
            $lock = null;
            try {
                $lock = Cache::store('file')->lock('woo_tick_queue:'.SyncQueue::NAME, $budget + 60);
                if (! $lock->get()) {
                    return;
                }
            } catch (\Throwable $e) {
                $lock = null;
            }

            try {
                $deadline = microtime(true) + $budget;

                do {
                    Artisan::call('queue:work', [
                        'connection' => SyncQueue::CONNECTION,
                        '--once' => true,
                        '--queue' => SyncQueue::NAME,
                        '--sleep' => 1,
                        '--tries' => 1,
                        '--timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 1200),
                    ]);

                    if ($runFinished()) {
                        break;
                    }
                } while (microtime(true) < $deadline && self::hasQueuedBatch());
            } finally {
                try {
                    if ($lock) {
                        $lock->release();
                    }
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** Whether any WooCommerce batch is waiting on the shared queue. */
    public static function hasQueuedBatch(): bool
    {
        try {
            return DB::table('jobs')->where('queue', SyncQueue::NAME)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
