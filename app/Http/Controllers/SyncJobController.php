<?php

namespace App\Http\Controllers;

use App\Models\SyncJob;
use App\Services\WooCommerce\InlineQueueRunner;
use App\Services\WooCommerce\SyncOptions;
use Illuminate\Http\Request;

class SyncJobController extends BaseController
{
    /**
     * Manual "no-cron" mode:
     * When the UI polls sync status, work this sync's queued batches inline for a
     * short budget. This makes "Sync now" finish on shared hosting without a
     * persistent queue worker, as long as the page stays open.
     */
    private function drainProductsQueue(SyncJob $job): void
    {
        $jobId = (int) $job->id;

        InlineQueueRunner::drain(function () use ($jobId) {
            $current = SyncJob::query()->find($jobId);

            return ! $current || ! in_array((string) $current->status, ['pending', 'running', 'cancelling'], true);
        });
    }

    /**
     * GET /api/woo-sync/latest
     * Returns latest running/cancelling sync job for current user.
     */
    public function latest(Request $request)
    {
        $userId = optional($request->user('api'))->id;
        if (!$userId) {
            return response()->json(['ok' => false, 'error' => 'Unauthenticated'], 401);
        }

        $job = SyncJob::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'running', 'cancelling'])
            ->orderByDesc('id')
            ->first();

        if (!$job) {
            return response()->json(['ok' => true, 'job' => null]);
        }

        return response()->json([
            'ok' => true,
            'job' => [
                'id' => $job->id,
                'status' => $job->status,
            ],
        ]);
    }

    /**
     * GET /api/sync/status/{id}
     */
    public function status(Request $request, int $id)
    {
        $job = SyncJob::query()->findOrFail($id);

        // If this sync is waiting on the next batch and no worker exists, run one batch inline.
        try {
            $stage = (string) ($job->stage ?? '');
            if ($job->status === 'running' && $stage !== '' && str_starts_with($stage, 'queued')) {
                $this->drainProductsQueue($job);
                // Reload after tick (status/stage/heartbeat may have changed)
                $job = SyncJob::query()->findOrFail($id);
            }
        } catch (\Throwable $e) {
        }

        // Stuck detection: if worker heartbeat hasn't moved, mark as failed so UI doesn't hang forever.
        $stuck = false;
        try {
            $stuckAfterSeconds = SyncOptions::int('stuck_seconds');
            $stuckAfterSeconds = max(60, min(3600, $stuckAfterSeconds));

            $stage = (string) ($job->stage ?? '');
            $effectiveStuckSeconds = $stuckAfterSeconds;

            // Between batches, the job may be queued waiting for the next worker tick.
            if ($stage !== '' && str_starts_with($stage, 'queued')) {
                $queueWait = SyncOptions::int('queue_wait_seconds');
                $queueWait = max(120, min(21600, $queueWait));
                $effectiveStuckSeconds = max($effectiveStuckSeconds, $queueWait);
            }

            // Media uploads can legitimately take longer on shared hosting.
            if ($stage === 'media') {
                $uploadTimeout = SyncOptions::int('media_upload_timeout', 60);
                $uploadTimeout = max(1, min(300, $uploadTimeout));
                $effectiveStuckSeconds = max($effectiveStuckSeconds, $uploadTimeout + 60);
            }

            if (in_array((string) $job->status, ['pending', 'running', 'cancelling'], true)) {
                $lastHeartbeat = optional($job->worker_heartbeat_at)->timestamp;
                if ($lastHeartbeat && (time() - (int) $lastHeartbeat) > $effectiveStuckSeconds) {
                    $stuck = true;
                    $job->status = 'failed';
                    $job->stage = 'failed';
                    $job->last_error = 'stuck: no worker heartbeat for '.$effectiveStuckSeconds.'s';
                    $job->cancel_requested = true; // stop worker ASAP if it's still alive
                    $job->finished_at = now();
                    $job->worker_heartbeat_at = now();
                    $job->save();
                }
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'total_items' => (int) $job->total_items,
            'processed_items' => (int) $job->processed_items,
            'success_items' => (int) $job->success_items,
            'failed_items' => (int) $job->failed_items,
            'percentage' => (int) $job->percentage,
            'stage' => $job->stage,
            'current_product_id' => $job->current_product_id,
            'current_sku' => $job->current_sku,
            'last_error' => $job->last_error,
            'cancel_requested' => (bool) $job->cancel_requested,
            'stuck' => $stuck,
            'worker_heartbeat_at' => optional($job->worker_heartbeat_at)->toDateTimeString(),
            'started_at' => optional($job->started_at)->toDateTimeString(),
            'finished_at' => optional($job->finished_at)->toDateTimeString(),
            'updated_at' => optional($job->updated_at)->toDateTimeString(),
        ]);
    }

    /**
     * POST /api/sync/{id}/cancel
     */
    public function cancel(Request $request, int $id)
    {
        $job = SyncJob::query()->findOrFail($id);

        // Signal cancel; worker will stop at next product boundary.
        // UX requirement: mark as cancelled immediately (not "cancelling").
        $job->cancel_requested = true;
        $job->status = 'cancelled';
        $job->stage = 'cancelled';
        $job->finished_at = now();
        $job->worker_heartbeat_at = now();
        $job->save();

        return response()->json(['ok' => true]);
    }
}

