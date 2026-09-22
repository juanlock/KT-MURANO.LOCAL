<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id', 'name', 'description', 'image', 'woocommerce_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
    ];
}
