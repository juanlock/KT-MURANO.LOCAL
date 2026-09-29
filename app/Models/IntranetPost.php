<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Intranet -> News: employee news / announcements / info posts (per company). */
class IntranetPost extends Model
{
    public const CATEGORIES = ['news', 'announcement', 'info'];

    protected $fillable = ['company_id', 'user_id', 'title', 'category', 'excerpt', 'body', 'cover_image', 'pinned', 'published', 'published_at'];

    protected $casts = ['pinned' => 'boolean', 'published' => 'boolean', 'published_at' => 'datetime'];

    protected $appends = ['cover_url'];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'firstname', 'lastname', 'username', 'avatar']);
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_image ? asset($this->cover_image) : null;
    }

    /** What employees see: published, and not scheduled for later. */
    public function scopeVisible($q)
    {
        return $q->where('published', true)->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /** Pinned first, then newest. */
    public function scopeFeedOrder($q)
    {
        return $q->orderByDesc('pinned')->orderByRaw('COALESCE(published_at, created_at) DESC')->orderByDesc('id');
    }
}
