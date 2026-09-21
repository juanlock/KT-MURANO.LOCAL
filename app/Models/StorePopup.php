<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StorePopup extends Model
{
    use HasTranslations;

    protected $table = 'store_popups';

    public const TYPES = ['announcement', 'subscription', 'sale'];

    public const TRIGGERS = ['immediate', 'delay', 'exit'];

    public const FREQUENCIES = ['once', 'session', 'always'];

    /** Customer-facing fields that can be translated per locale. */
    public const TRANSLATABLE = ['title', 'message', 'cta_label'];

    protected $fillable = [
        'title', 'message', 'type', 'image', 'cta_label', 'cta_url',
        'title_translations', 'message_translations', 'cta_label_translations',
        'enabled', 'trigger', 'delay_seconds', 'frequency',
        'starts_at', 'ends_at', 'sort_order',
    ];

    protected $casts = [
        'title_translations' => 'array',
        'message_translations' => 'array',
        'cta_label_translations' => 'array',
        'enabled' => 'boolean',
        'delay_seconds' => 'integer',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** Enabled popups whose optional date window is currently open. */
    public function scopeRunning(Builder $q): Builder
    {
        $now = now();

        return $q->where('enabled', true)
            ->where(fn ($qq) => $qq->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($qq) => $qq->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }
}
