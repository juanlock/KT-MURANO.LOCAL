<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreQuoteRequest extends Model
{
    protected $table = 'store_quote_requests';

    public const STATUSES = ['new', 'handled', 'closed'];

    protected $fillable = [
        'product_id', 'product_name', 'client_id',
        'name', 'email', 'phone', 'quantity', 'message', 'status',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'client_id' => 'integer',
        'quantity' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
