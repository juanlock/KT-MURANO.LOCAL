<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SallaSetting extends Model
{
    protected $table = 'salla_settings';

    protected $fillable = [
        'enabled', 'client_id', 'client_secret',
        'access_token', 'refresh_token', 'access_token_expires_at', 'refresh_token_expires_at',
        'store_id', 'store_name', 'store_domain', 'webhook_secret',
        'warehouse_id', 'last_sync_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'store_id' => 'integer',
        'warehouse_id' => 'integer',
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConnected(): bool
    {
        return ! empty($this->access_token);
    }

    public function hasApiCredentials(): bool
    {
        return ! empty($this->client_id) && ! empty($this->client_secret);
    }
}
