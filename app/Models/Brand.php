<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use \App\Models\Concerns\ScopedToStoreCompany; // the public storefront sees only its company (2026-11-25)
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id', 'name', 'description', 'image', 'woocommerce_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
    ];
}
