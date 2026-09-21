<?php

namespace App\Console\Commands;

use App\Jobs\WooCommerceProductsPullJob;
use App\Jobs\WooCommerceProductsSyncJob;
use App\Jobs\WooCommerceStockSyncJob;
use App\Models\Product;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\WooCommerceSetting;
use App\Services\WooCommerce\SyncQueue;
use App\Services\WooCommerce\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WooCommerceSync extends Command
{
    protected $signature = 'woocommerce:sync
        {--scope=all : products|stock|all (push) or pull-products|orders|customers|pull (Woo -> Stocky)}
        {--only-unsynced : For products push, sync only products without woocommerce_id}
        {--user= : User id imported orders are attributed to (default: first active all-warehouse user)}
        {--full : For --scope=orders, rescan every order instead of only those changed since the last run}';

    protected $description = 'Run WooCommerce sync using the same jobs as manual sync (push: products, stock; pull: products, orders, customers)';

    private const ACTIVE_PRODUCTS_SYNC_TOKENS_KEY = 'woo_products_sync_active_tokens';

    /** Cache key holding the UTC timestamp the last successful orders pull started at. */
    private const ORDERS_CURSOR_KEY = 'woo_orders_pull_cursor';

    /** Overlap re-scanned on each incremental orders pull, to absorb clock skew. */
    private const ORDERS_CURSOR_OVERLAP_MINUTES = 10;

    private const MAX_WAIT_SECONDS = 7200; // 2 hours max per phase

    private function progressCache()
    {
        return Cache::store('file');
    }

    private function addActiveProductsSyncToken(string $token): void
    {
        try {
            $tokens = $this->progressCache()->get(self::ACTIVE_PRODUCTS_SYNC_TOKENS_KEY, []);
            if (! is_array($tokens)) {
                $tokens = [];
            }
            $tokens[$token] = now()->toDateTimeString();
            $this->progressCache()->put(self::ACTIVE_PRODUCTS_SYNC_TOKENS_KEY, $tokens, 3600);
        } catch (\Throwable $e) {
        }
    }

    public function handle(): int
    {
        $settings = WooCommerceSetting::first();
        if (! $settings) {
            $this->warn('WooCommerce is not configured.');

            return 0;
        }

        $scope = strtolower((string) $this->option('scope'));
        $onlyUnsynced = (bool) $this->option('only-unsynced');

        $valid = ['products', 'stock', 'all', 'pull-products', 'orders', 'customers', 'pull'];
        if (! in_array($scope, $valid, true)) {
            $this->error('Invalid scope. Use: '.implode(', ', $valid));

            return 1;
        }

        // Push (Stocky -> Woo). "all" stays push-only for backwards compatibility;
        // use "pull" for the Woo -> Stocky direction.
        $runProducts = ($scope === 'products' || $scope === 'all');
        $runStock = ($scope === 'stock' || $scope === 'all');

        // Pull (Woo -> Stocky).
        $runPullProducts = ($scope === 'pull-products' || $scope === 'pull');
        $runPullOrders = ($scope === 'orders' || $scope === 'pull');
        $runPullCustomers = ($scope === 'customers' || $scope === 'pull');

        if ($runProducts) {
            if ($this->runProductsPushSync($onlyUnsynced) !== 0) {
                return 1;
            }
        }

        if ($runStock) {
            if ($this->runStockSync() !== 0) {
                return 1;
            }
        }

        if ($runPullProducts) {
            if ($this->runProductsPull() !== 0) {
                return 1;
            }
        }

        if ($runPullOrders) {
            if ($this->runOrdersPull($settings) !== 0) {
                return 1;
            }
        }

        if ($runPullCustomers) {
            if ($this->runCustomersPull($settings) !== 0) {
                return 1;
            }
        }

        $settings->refresh();
        $settings->last_sync_at = now();
        $settings->save();

        $this->info('WooCommerce sync completed.');

        return 0;
    }

    /**
     * Dispatch products push job (same as manual sync) and process queue until finished.
     */
    private function runProductsPushSync(bool $onlyUnsynced): int
    {
        $total = (int) Product::whereNull('deleted_at')
            ->when($onlyUnsynced, fn ($q) => $q->whereNull('woocommerce_id'))
            ->count();

        $syncJob = SyncJob::create([
            'user_id' => null,
            'warehouse_id' => null,
            'status' => 'running',
            'total_items' => $total,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
            'percentage' => 0,
            'stage' => 'queued',
            'current_product_id' => null,
            'current_sku' => null,
            'last_error' => null,
            'started_at' => now(),
            'finished_at' => null,
            'cancel_requested' => false,
            'worker_heartbeat_at' => now(),
        ]);

        $token = 'woo_products_sync_'.uniqid();
        $this->progressCache()->put($token, [
            'total_products' => $total,
            'synced_products' => 0,
            'failed_products' => 0,
            'percentage' => 0,
            'created' => 0,
            'updated' => 0,
            'processed' => 0,
            'stage' => 'queued',
            'current_product_id' => null,
            'current_sku' => null,
            'sync_job_id' => (int) $syncJob->id,
            'heartbeat_at' => now()->toDateTimeString(),
            'started_at' => now()->toDateTimeString(),
            'finished' => false,
            'error' => null,
        ], 3600);

        $this->addActiveProductsSyncToken($token);

        $queue = SyncQueue::NAME;
        WooCommerceProductsSyncJob::dispatch($token, $onlyUnsynced, (int) $syncJob->id)
            ->onConnection(SyncQueue::CONNECTION)
            ->onQueue($queue);

        $this->info('Products push: job dispatched (same as manual sync), processing queue...');

        return $this->processQueueUntilFinished($queue, $token, 'Products push', self::MAX_WAIT_SECONDS);
    }

    /**
     * Dispatch stock sync job (same as manual sync) and process queue until finished.
     */
    private function runStockSync(): int
    {
        $total = (int) Product::whereNull('deleted_at')->whereNotNull('woocommerce_id')->count();
        $token = 'woo_stock_sync_'.uniqid();
        $queue = SyncQueue::NAME;

        $this->progressCache()->put($token, [
            'total_products' => $total,
            'synced_products' => 0,
            'failed_products' => 0,
            'processed' => 0,
            'percentage' => 0,
            'stage' => 'queued',
            'queue' => $queue,
            'worker_heartbeat_at' => now()->toDateTimeString(),
            'started_at' => now()->toDateTimeString(),
            'finished' => false,
            'error' => null,
        ], 3600);

        WooCommerceStockSyncJob::dispatch($token)
            ->onConnection(SyncQueue::CONNECTION)
            ->onQueue($queue);

        $this->info('Stock sync: job dispatched (same as manual sync), processing queue...');

        return $this->processQueueUntilFinished($queue, $token, 'Stock sync', self::MAX_WAIT_SECONDS);
    }

    /**
     * Dispatch the products PULL job (Woo -> Stocky) and process the queue until finished.
     *
     * Mirrors runProductsPushSync(); total_items starts at 0 because the job fills it
     * in from the X-WP-Total header of the first page it fetches.
     */
    private function runProductsPull(): int
    {
        $syncJob = SyncJob::create([
            'user_id' => null,
            'warehouse_id' => null,
            'status' => 'running',
            'total_items' => 0,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
            'percentage' => 0,
            'stage' => 'queued',
            'current_product_id' => null,
            'current_sku' => null,
            'last_error' => null,
            'started_at' => now(),
            'finished_at' => null,
            'cancel_requested' => false,
            'worker_heartbeat_at' => now(),
        ]);

        $token = 'woo_products_pull_'.uniqid();
        $this->progressCache()->put($token, [
            'total_products' => 0,
            'synced_products' => 0,
            'failed_products' => 0,
            'percentage' => 0,
            'created' => 0,
            'updated' => 0,
            'processed' => 0,
            'stage' => 'queued',
            'cursor_page' => 1,
            'cursor_index' => 0,
            'sync_job_id' => (int) $syncJob->id,
            'worker_heartbeat_at' => now()->toDateTimeString(),
            'heartbeat_at' => now()->toDateTimeString(),
            'started_at' => now()->toDateTimeString(),
            'finished' => false,
            'error' => null,
        ], 3600);

        $this->addActiveProductsSyncToken($token);

        $queue = SyncQueue::NAME;
        WooCommerceProductsPullJob::dispatch($token, false, (int) $syncJob->id)
            ->onConnection(SyncQueue::CONNECTION)
            ->onQueue($queue);

        $this->info('Products pull: job dispatched, processing queue...');

        return $this->processQueueUntilFinished($queue, $token, 'Products pull', self::MAX_WAIT_SECONDS);
    }

    /**
     * Pull WooCommerce orders into Stocky sales.
     *
     * Runs inline (no queue): pullOrders() is already page-by-page and idempotent.
     * Incremental by default -- a full history rescan on every schedule tick would be
     * one API call per 50 orders, forever. The cursor only moves forward on a clean
     * run so a failed order (usually an unmapped product) is retried next time.
     */
    private function runOrdersPull(WooCommerceSetting $settings): int
    {
        $userId = $this->resolveImportUserId();
        if ($userId <= 0) {
            $this->error('Orders pull: no active user found to attribute imported sales to.');

            return 1;
        }

        $cursor = (bool) $this->option('full')
            ? ''
            : (string) $this->progressCache()->get(self::ORDERS_CURSOR_KEY, '');
        $modifiedAfter = $cursor !== '' ? $cursor : null;

        // Captured before the request so orders changed mid-run are picked up next time.
        $runStartedAt = now()->utc();

        $this->info($modifiedAfter !== null
            ? 'Orders pull: incremental (modified after '.$modifiedAfter.' UTC), user #'.$userId.'...'
            : 'Orders pull: full scan, user #'.$userId.'...');

        // warehouseId null => pullOrders() falls back to the configured default warehouse.
        $result = SyncService::fromSettings($settings)->pullOrders($userId, null, null, $modifiedAfter);

        if (empty($result['ok'])) {
            $this->error('Orders pull failed: '.((string) ($result['error'] ?? 'unknown error')));

            return 1;
        }

        $errors = (int) ($result['errors'] ?? 0);
        $this->line(sprintf(
            'Orders pull: created=%d updated=%d skipped=%d errors=%d processed=%d',
            (int) ($result['created'] ?? 0),
            (int) ($result['updated'] ?? 0),
            (int) ($result['skipped'] ?? 0),
            $errors,
            (int) ($result['processed'] ?? 0)
        ));

        if ($errors > 0) {
            $this->warn('Orders pull: '.$errors.' order(s) failed; keeping the previous cursor so they are retried. See the WooCommerce logs.');

            return 0;
        }

        $this->progressCache()->forever(
            self::ORDERS_CURSOR_KEY,
            $runStartedAt->copy()->subMinutes(self::ORDERS_CURSOR_OVERLAP_MINUTES)->format('Y-m-d\TH:i:s')
        );

        return 0;
    }

    /**
     * Pull WooCommerce customers into Stocky clients. Runs inline; full scan
     * (the Woo customers endpoint has no reliable "modified since" filter).
     */
    private function runCustomersPull(WooCommerceSetting $settings): int
    {
        $this->info('Customers pull: starting...');

        $result = SyncService::fromSettings($settings)->pullCustomers();

        $this->line('Customers pull: '.json_encode($result));

        return 0;
    }

    /**
     * User that imported sales are attributed to. Sales require a user_id and cron
     * has no authenticated user, so prefer an explicit --user, then an active
     * all-warehouse user, then simply the lowest active user id.
     */
    private function resolveImportUserId(): int
    {
        $explicit = (int) $this->option('user');
        if ($explicit > 0) {
            $exists = User::whereNull('deleted_at')->where('id', $explicit)->exists();
            if (! $exists) {
                $this->error('User #'.$explicit.' not found.');

                return 0;
            }

            return $explicit;
        }

        $user = User::whereNull('deleted_at')
            ->where('statut', 1)
            ->orderByDesc('is_all_warehouses')
            ->orderBy('id')
            ->first();

        return $user ? (int) $user->id : 0;
    }

    /**
     * Run queue:work for the given queue until the progress state is finished or timeout.
     */
    private function processQueueUntilFinished(string $queue, string $token, string $label, int $maxWaitSeconds): int
    {
        $timeout = (int) env('QUEUE_WORKER_TIMEOUT', 1200);
        $timeout = max(60, min(3600, $timeout));

        $deadline = time() + $maxWaitSeconds;
        $lastOutput = 0;

        while (time() < $deadline) {
            $state = $this->progressCache()->get($token, null);
            if (is_array($state) && ! empty($state['finished'])) {
                $error = $state['error'] ?? null;
                if ($error) {
                    $this->warn("{$label} finished with error: {$error}");
                }
                return 0;
            }

            if (! Schema::hasTable('jobs')) {
                $this->warn('Jobs table missing. Run queue worker separately.');

                return 1;
            }

            $hasJob = DB::table('jobs')->where('queue', $queue)->exists();
            if (! $hasJob) {
                // No job in queue; check state again (job may have finished and cleared queue)
                $state = $this->progressCache()->get($token, null);
                if (is_array($state) && ! empty($state['finished'])) {
                    return 0;
                }
                // Job might have re-dispatched with a slight delay; wait and retry
                sleep(2);
                continue;
            }

            Artisan::call('queue:work', [
                'connection' => 'database',
                '--once' => true,
                '--queue' => $queue,
                '--sleep' => 1,
                '--tries' => 1,
                '--timeout' => $timeout,
            ]);

            if (time() - $lastOutput >= 15) {
                $state = $this->progressCache()->get($token, []);
                $pct = (int) ($state['percentage'] ?? 0);
                $processed = (int) ($state['processed'] ?? 0);
                $total = (int) ($state['total_products'] ?? 0);
                $this->line("{$label}: {$processed}/{$total} ({$pct}%)");
                $lastOutput = time();
            }
        }

        $this->error("{$label} did not finish within {$maxWaitSeconds}s.");

        return 1;
    }
}
