<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSetting extends Model
{
    protected $table = 'telegram_settings';

    protected $fillable = ['enabled', 'bot_token', 'chat_id', 'events'];

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
        return ! empty($this->bot_token) && ! empty($this->chat_id);
    }

    /** null/[] events = subscribed to everything. */
    public function subscribesTo(string $event): bool
    {
        $events = $this->events ?: [];

        return ! count($events) || in_array($event, $events, true);
    }
}
