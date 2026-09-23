<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-company override of a Language row's is_active state -- see
 * 2026_09_23_150000_create_company_language_settings_table.php.
 */
class CompanyLanguageSetting extends Model
{
    protected $fillable = ['company_id', 'language_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
