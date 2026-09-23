<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Real parent for the "umbrella name shared by many independently priced
 * product rows" case (e.g. a services company's "Chemical Application
 * Services" covering many separately priced line items).
 *
 * Replaces billing's flat products.sub_name + app-only (company_id, name,
 * sub_name) uniqueness hack with a real FK'd parent row -- see the
 * 2026_09_22_1600xx migrations for the schema and reasoning.
 *
 * NOT the same concept as ProductVariant (true Size/Color-style variants of
 * one product row, sharing category/image/brand) -- that stays untouched.
 */
class ProductGroup extends Model
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id', 'name',
    ];

    protected $casts = [
        'company_id' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'product_group_id');
    }
}
