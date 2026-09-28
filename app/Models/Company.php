<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name', 'email', 'phone', 'country',
        // Own website domain(s), comma-separated (App\Support\CompanyDomain)
        'domains',
    ];
}
