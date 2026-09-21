<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRewardRedemption extends Model
{
    protected $table = 'loyalty_reward_redemptions';

    public const STATUSES = ['issued', 'fulfilled', 'cancelled'];

    protected $fillable = [
        'reward_id', 'client_id', 'reward_name', 'reward_type', 'points_spent',
        'status', 'channel', 'reference_type', 'reference_id', 'code', 'note',
        'created_by', 'fulfilled_by', 'fulfilled_at',
    ];

    protected $casts = [
        'points_spent' => 'decimal:2',
        'fulfilled_at' => 'datetime',
    ];

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
