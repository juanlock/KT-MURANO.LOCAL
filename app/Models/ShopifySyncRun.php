<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sync operation: which store, which entity, which way, and how far it got.
 *
 * Progress is written as the run advances so the UI can poll it, and
 * `cancel_requested` is the only way a run is stopped — the worker checks it
 * between batches rather than being killed mid-write.
 */
class ShopifySyncRun extends Model
{
    protected $table = 'shopify_sync_runs';

    protected $fillable = [
        'store_id',
        'user_id',
        'entity',
        'direction',
        'status',
        'dry_run',
        'total_items',
        'processed_items',
        'created_items',
        'updated_items',
        'skipped_items',
        'failed_items',
        'percentage',
        'stage',
        'cursor',
        'last_error',
        'started_at',
        'finished_at',
        'cancel_requested',
        'heartbeat_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'cancel_requested' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(ShopifyStore::class, 'store_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }

    /**
     * A run whose worker stopped writing heartbeats is stale — the PHP process
     * died, was killed, or timed out. Without this the UI would show "running"
     * for ever and refuse to start a replacement.
     */
    public function isStale(int $minutes = 10): bool
    {
        if ($this->status !== 'running') {
            return false;
        }

        $beat = $this->heartbeat_at ?: $this->started_at;

        return ! $beat || $beat->lt(now()->subMinutes($minutes));
    }

    public function durationSeconds(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?: now());
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'entity' => $this->entity,
            'direction' => $this->direction,
            'status' => $this->isStale() ? 'stale' : $this->status,
            'dry_run' => (bool) $this->dry_run,
            'total' => (int) $this->total_items,
            'processed' => (int) $this->processed_items,
            'created' => (int) $this->created_items,
            'updated' => (int) $this->updated_items,
            'skipped' => (int) $this->skipped_items,
            'failed' => (int) $this->failed_items,
            'percentage' => (int) $this->percentage,
            'stage' => $this->stage,
            'last_error' => $this->last_error,
            'started_at' => $this->started_at ? $this->started_at->toDateTimeString() : null,
            'finished_at' => $this->finished_at ? $this->finished_at->toDateTimeString() : null,
            'duration' => $this->durationSeconds(),
            'cancel_requested' => (bool) $this->cancel_requested,
        ];
    }
}
