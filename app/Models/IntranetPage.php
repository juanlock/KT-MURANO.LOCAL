<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Intranet -> static pages (slug unique per company), listed in the intranet navigation. */
class IntranetPage extends Model
{
    protected $fillable = ['company_id', 'title', 'slug', 'content', 'published', 'sort_order'];

    protected $casts = ['published' => 'boolean', 'sort_order' => 'integer'];
}
