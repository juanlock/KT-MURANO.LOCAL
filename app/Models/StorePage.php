<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class StorePage extends Model
{
    use HasTranslations;

    /** Customer-facing copy that can be translated per locale. */
    public const TRANSLATABLE = ['title', 'content', 'seo_title', 'seo_description'];

    protected $fillable = [
        'title', 'slug', 'content', 'seo_title', 'seo_description', 'published',
        'title_translations', 'content_translations',
        'seo_title_translations', 'seo_description_translations',
    ];

    protected $casts = [
        'published' => 'boolean',
        'title_translations' => 'array',
        'content_translations' => 'array',
        'seo_title_translations' => 'array',
        'seo_description_translations' => 'array',
    ];
}
