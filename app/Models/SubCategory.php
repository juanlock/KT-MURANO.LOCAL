<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model
{
    use \App\Models\Concerns\ScopedToStoreCompany; // the public storefront sees only its company (2026-11-25)
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


