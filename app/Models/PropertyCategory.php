<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PropertyCategory extends Model
{
    use SoftDeletes;

    protected $table = 'property_categories';

    protected $fillable = [
        'company_id', 'name', 'slug', 'description', 'image',
    ];

    protected $casts = [
        'company_id' => 'integer',
    ];

    public function properties()
    {
        return $this->hasMany(Property::class, 'property_category_id');
    }
}
