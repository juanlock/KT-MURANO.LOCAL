<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsCity extends Model
{
    protected $table = 'uscities';

    protected $fillable = [
        'source_id', 'city', 'city_ascii', 'state_id', 'state_name',
        'county_fips', 'county_name', 'lat', 'lng', 'population', 'density',
        'military', 'incorporated', 'timezone', 'ranking', 'zips',
    ];

    protected $casts = [
        'military' => 'boolean',
        'incorporated' => 'boolean',
        'lat' => 'float',
        'lng' => 'float',
        'population' => 'integer',
        'density' => 'float',
        'ranking' => 'integer',
    ];
}
