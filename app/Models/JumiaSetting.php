<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JumiaSetting extends Model
{
    protected $table = 'jumia_settings';

    protected $fillable = [
        'enabled', 'api_url', 'user_email', 'api_key', 'warehouse_id', 'last_sync_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
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
        return ! empty($this->api_url) && ! empty($this->user_email) && ! empty($this->api_key);
    }
}
