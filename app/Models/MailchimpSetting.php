<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailchimpSetting extends Model
{
    protected $table = 'mailchimp_settings';

    protected $fillable = [
        'enabled', 'api_key', 'list_id', 'list_name', 'double_opt_in', 'auto_sync', 'last_sync_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'double_opt_in' => 'boolean',
        'auto_sync' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * In-memory defaults so the FIRST current() call (firstOrCreate) already
     * carries the consent-safe double_opt_in=true — a fresh model instance
     * does not read DB column defaults back, and the settings page must never
     * render the opt-in switch off on first load.
     */
    protected $attributes = [
        'double_opt_in' => true,
    ];

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->api_key);
    }

    public function isReady(): bool
    {
        return $this->isConfigured() && ! empty($this->list_id);
    }
}
