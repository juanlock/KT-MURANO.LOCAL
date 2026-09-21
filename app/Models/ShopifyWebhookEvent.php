<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An inbound webhook, stored before it is acted on.
 *
 * Shopify retries a webhook until it receives a 2xx, so the same event arrives
 * several times. Recording the event id first and processing second is what
 * stops one order being imported three times.
 */
class ShopifyWebhookEvent extends Model
{
    protected $table = 'shopify_webhook_events';

    protected $fillable = [
        'store_id',
        'topic',
        'event_id',
        'payload',
        'status',
        'error',
        'processed_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(ShopifyStore::class, 'store_id');
    }

    public function markProcessed(): void
    {
        $this->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'processed_at' => now(), 'error' => mb_substr($error, 0, 2000)]);
    }
}
