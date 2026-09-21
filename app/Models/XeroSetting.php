<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XeroSetting extends Model
{
    protected $table = 'xero_settings';

    protected $fillable = [
        'enabled', 'client_id', 'client_secret',
        'access_token', 'refresh_token', 'access_token_expires_at',
        'tenant_id', 'tenant_name',
        'sales_account_code', 'payment_account_code', 'auto_sync', 'last_sync_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'auto_sync' => 'boolean',
        'access_token_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConnected(): bool
    {
        return ! empty($this->refresh_token) && ! empty($this->tenant_id);
    }

    public function hasApiCredentials(): bool
    {
        return ! empty($this->client_id) && ! empty($this->client_secret);
    }
}
