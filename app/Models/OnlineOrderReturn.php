<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineOrderReturn extends Model
{
    protected $table = 'online_order_returns';

    public const TYPES = ['cancellation', 'return'];
    public const STATUSES = ['requested', 'approved', 'rejected', 'refunded'];

    protected $fillable = [
        'order_id', 'client_id', 'type', 'status', 'reason', 'admin_note',
        'refund_amount', 'refunded_at', 'refund_reference', 'processed_by',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'client_id' => 'integer',
        'refund_amount' => 'decimal:2',
        'refunded_at' => 'datetime',
        'processed_by' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderReturnItem::class, 'return_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'approved'], true);
    }
}
