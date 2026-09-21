<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreCoupon extends Model
{
    use SoftDeletes;

    protected $table = 'store_coupons';

    public const TYPES = ['percentage', 'fixed'];

    protected $fillable = [
        'code', 'description', 'type', 'value',
        'min_order_amount', 'max_discount',
        'usage_limit', 'used_count', 'per_customer_limit',
        'starts_at', 'ends_at', 'enabled',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'per_customer_limit' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'enabled' => 'boolean',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(StoreCouponRedemption::class, 'coupon_id');
    }

    /** Compute the discount this coupon yields on a subtotal (already capped/limited). */
    public function discountFor(float $subtotal): float
    {
        if ($this->type === 'fixed') {
            $discount = (float) $this->value;
        } else {
            $discount = $subtotal * ((float) $this->value) / 100;
            if ($this->max_discount !== null) {
                $discount = min($discount, (float) $this->max_discount);
            }
        }

        // Never discount more than the subtotal.
        return round(max(0, min($discount, $subtotal)), 2);
    }
}
