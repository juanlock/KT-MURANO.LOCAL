<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WalletWithdrawal extends Model
{
    use SoftDeletes;

    protected $table = 'wallet_withdrawals';

    public const STATUSES = ['pending', 'approved', 'rejected', 'paid'];

    protected $fillable = [
        'wallet_id', 'amount', 'status', 'method', 'destination',
        'note', 'processed_by', 'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }
}
