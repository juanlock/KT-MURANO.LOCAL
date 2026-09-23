<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreBanner extends Model
{
    protected $fillable = ['company_id', 'title', 'position', 'link', 'image', 'active'];

    protected $casts = ['active' => 'boolean'];
}
