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
        'company_id',
        'title', 'slug', 'content', 'seo_title', 'seo_description', 'published',
        // Imported website shown in its original design (see migration
        // 2026_09_28_120000_add_site_path_to_store_pages)
        'site_path', 'use_site_design',
        'title_translations', 'content_translations',
        'seo_title_translations', 'seo_description_translations',
    ];

    protected $casts = [
        'published' => 'boolean',
        'use_site_design' => 'boolean',
        'title_translations' => 'array',
        'content_translations' => 'array',
        'seo_title_translations' => 'array',
        'seo_description_translations' => 'array',
    ];
}
