<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineOrderReturnItem extends Model
{
    protected $table = 'online_order_return_items';

    protected $fillable = [
        'return_id', 'online_order_item_id', 'product_id', 'product_variant_id', 'qty', 'amount',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'amount' => 'decimal:2',
    ];

    public function return(): BelongsTo
    {
        return $this->belongsTo(OnlineOrderReturn::class, 'return_id');
    }
}
