<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model
{
    protected $table = 'subcategories';

    protected $fillable = [
        'company_id',
        'category_id',
        'name',
        'description',
        'status',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'category_id' => 'integer',
        'status' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_subcategory', 'sub_category_id', 'product_id')
            ->withTimestamps();
    }
}


