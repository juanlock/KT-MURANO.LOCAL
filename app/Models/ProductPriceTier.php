<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One quantity break of a product's wholesale price ladder.
 *
 * `min_qty`/`max_qty` bound the bracket in sale units (max_qty NULL = the
 * open-ended top tier), `price` is the per-piece price charged inside it, in
 * the same basis as products.price. Resolution lives in
 * {@see \App\Services\WholesalePricingService}.
 */
class ProductPriceTier extends Model
{
    use SoftDeletes;

    protected $table = 'product_price_tiers';

    protected $fillable = [
        'product_id',
        'min_qty',
        'max_qty',
        'price',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'min_qty' => 'double',
        'max_qty' => 'double',
        'price' => 'double',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
