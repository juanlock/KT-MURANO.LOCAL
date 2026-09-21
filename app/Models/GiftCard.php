<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GiftCard extends Model
{
    use SoftDeletes;

    protected $table = 'gift_cards';

    public const TYPES = ['gift_card', 'voucher', 'store_credit'];

    public const STATUSES = ['active', 'redeemed', 'disabled', 'expired'];

    protected $fillable = [
        'type', 'code', 'name', 'amount', 'balance', 'status',
        'expires_at', 'note', 'created_by', 'redeemed_by', 'redeemed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'redeemed_by');
    }

    /** True when the card has been disabled or its expiry has passed. */
    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    /** A card that can still be (partly) redeemed. */
    public function isRedeemable(): bool
    {
        return $this->status === 'active'
            && ! $this->isExpired()
            && (float) $this->balance > 0;
    }
}
