<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $table = 'wallet_transactions';

    public const TYPES = ['credit', 'debit'];

    public const SOURCES = ['checkout', 'pos_sale', 'refund', 'withdrawal', 'adjustment', 'gift_card'];

    protected $fillable = [
        'wallet_id', 'type', 'amount', 'balance_after',
        'source', 'reference_type', 'reference_id', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
