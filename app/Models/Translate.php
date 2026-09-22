<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Translate extends Model
{
    protected $table = 'translations';

    use HasFactory;

    protected $dates = ['deleted_at'];

    // Locale columns are dynamic (one per row in `languages`), so $fillable
    // can't enumerate them -- see 2026_09_21_190000_pivot_translations_to_wide_table.
    protected $guarded = ['id'];
}
