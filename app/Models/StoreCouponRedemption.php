<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCouponRedemption extends Model
{
    protected $table = 'store_coupon_redemptions';

    protected $fillable = [
        'coupon_id', 'client_id', 'online_order_id', 'discount',
    ];

    protected $casts = [
        'coupon_id' => 'integer',
        'client_id' => 'integer',
        'online_order_id' => 'integer',
        'discount' => 'decimal:2',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(StoreCoupon::class, 'coupon_id');
    }
}
