<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id', 'name', 'ShortName', 'base_unit', 'operator', 'operator_value', 'is_active',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'base_unit' => 'integer',
        'operator_value' => 'float',
        'is_active' => 'integer',

    ];
}
