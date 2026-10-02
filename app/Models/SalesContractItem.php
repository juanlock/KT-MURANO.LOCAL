<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A line of a sales contract: what each recurring invoice bills. */
class SalesContractItem extends Model
{
    protected $fillable = ['sales_contract_id', 'product_id', 'name', 'quantity', 'price', 'tax_percent', 'tax_method'];

    protected $casts = [
        'product_id' => 'integer',
        'quantity' => 'float',
        'price' => 'float',
        'tax_percent' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Line total incl. tax (exclusive tax added on top; inclusive already in the price). */
    public function total(): float
    {
        $base = (float) $this->quantity * (float) $this->price;
        $tax = (string) $this->tax_method === '1' ? $base * (float) $this->tax_percent / 100 : 0;

        return round($base + $tax, 2);
    }
}
