<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Wallet extends Model
{
    use SoftDeletes;

    protected $table = 'wallets';

    public const STATUSES = ['active', 'frozen'];

    protected $fillable = [
        'client_id', 'balance', 'currency', 'status',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(WalletWithdrawal::class, 'wallet_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
