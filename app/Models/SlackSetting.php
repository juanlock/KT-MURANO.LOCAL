<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlackSetting extends Model
{
    protected $table = 'slack_settings';

    protected $fillable = ['enabled', 'webhook_url', 'events'];

    protected $casts = [
        'enabled' => 'boolean',
        'events' => 'array',
    ];

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->webhook_url);
    }

    /** null/[] events = subscribed to everything. */
    public function subscribesTo(string $event): bool
    {
        $events = $this->events ?: [];

        return ! count($events) || in_array($event, $events, true);
    }
}
