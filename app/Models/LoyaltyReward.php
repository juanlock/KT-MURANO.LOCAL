<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoyaltyReward extends Model
{
    use SoftDeletes;

    protected $table = 'loyalty_rewards';

    public const TYPES = ['gift_card', 'voucher', 'product'];

    protected $fillable = [
        'name', 'description', 'type', 'points_cost', 'value',
        'product_id', 'image', 'active', 'stock', 'per_customer_limit', 'sort_order',
    ];

    protected $casts = [
        'points_cost' => 'decimal:2',
        'value' => 'decimal:2',
        'active' => 'boolean',
        'stock' => 'integer',
        'per_customer_limit' => 'integer',
        'sort_order' => 'integer',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRewardRedemption::class, 'reward_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Whether the reward can currently be redeemed (active + in stock). */
    public function isRedeemable(): bool
    {
        return $this->active && ($this->stock === null || $this->stock > 0);
    }
}
