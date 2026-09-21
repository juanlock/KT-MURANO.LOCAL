<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrestashopSetting extends Model
{
    protected $table = 'prestashop_settings';

    protected $fillable = [
        'enabled', 'store_url', 'api_key', 'default_language_id', 'warehouse_id', 'last_sync_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'default_language_id' => 'integer',
        'warehouse_id' => 'integer',
        'last_sync_at' => 'datetime',
    ];

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->store_url) && ! empty($this->api_key);
    }
}
