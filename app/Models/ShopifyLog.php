<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The audit trail. Every remote write and every failure lands here, because
 * when a shop's catalogue looks wrong the only useful question is "what did we
 * actually send, and when".
 */
class ShopifyLog extends Model
{
    protected $table = 'shopify_logs';

    protected $fillable = [
        'store_id',
        'run_id',
        'entity',
        'action',
        'level',
        'message',
        'context',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(ShopifyStore::class, 'store_id');
    }

    public static function write(?int $storeId, string $action, string $message, string $level = 'info', array $context = [], ?int $runId = null, ?string $entity = null): void
    {
        // Logging must never be the thing that breaks a sync.
        try {
            static::create([
                'store_id' => $storeId,
                'run_id' => $runId,
                'entity' => $entity,
                'action' => $action,
                'level' => $level,
                'message' => mb_substr($message, 0, 2000),
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // swallowed on purpose
        }
    }
}
