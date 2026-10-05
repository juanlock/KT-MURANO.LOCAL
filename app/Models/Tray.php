<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tray extends Model
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    // Stations are per company (company_id).
    protected $fillable = [
        'company_id',
        'name',
        'description',
    ];
}
