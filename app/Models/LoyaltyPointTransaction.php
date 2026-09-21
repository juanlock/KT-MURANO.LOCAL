<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointTransaction extends Model
{
    protected $table = 'loyalty_point_transactions';

    public const TYPES = ['earn', 'redeem', 'adjustment', 'reversed'];

    protected $fillable = [
        'client_id', 'type', 'points', 'balance_after',
        'source', 'reference_type', 'reference_id', 'note', 'created_by',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
