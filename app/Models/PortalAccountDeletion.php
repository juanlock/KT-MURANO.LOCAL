<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Record of a customer closing their own Client Portal account.
 * Kept for audit — the portal login row itself is deleted.
 */
class PortalAccountDeletion extends Model
{
    protected $table = 'portal_account_deletions';

    protected $fillable = [
        'client_id', 'email', 'client_name', 'reason', 'ip',
    ];

    protected $casts = [
        'client_id' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
