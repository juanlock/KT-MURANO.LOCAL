<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    protected $table = 'product_reviews';

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'product_id', 'client_id', 'online_order_id',
        'reviewer_name', 'rating', 'comment', 'status',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'client_id' => 'integer',
        'online_order_id' => 'integer',
        'rating' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', 'approved');
    }
}
