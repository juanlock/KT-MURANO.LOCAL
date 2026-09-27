<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = [
        'name', 'iso2', 'demonym', 'tld', 'currency', 'population', 'density',
        'area', 'gdp', 'median_age', 'language', 'website', 'calling_code',
        'driving_side', 'continent', 'un_member', 'religion',
    ];

    protected $casts = [
        'un_member' => 'boolean',
        'population' => 'integer',
        'density' => 'float',
        'area' => 'integer',
        'gdp' => 'integer',
        'median_age' => 'float',
    ];
}
