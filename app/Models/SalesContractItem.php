<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A line of a sales contract: what each recurring invoice bills. */
class SalesContractItem extends Model
{
    // note: the line's note on each invoice -- a template filled per invoice
    // (SalesContractBilling::renderNote(), e.g. "Note: {{month}} installment services").
    protected $fillable = ['sales_contract_id', 'product_id', 'name', 'note', 'quantity', 'price', 'tax_percent', 'tax_method'];

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

    /**
     * Line total incl. its own tax (exclusive tax added on top; inclusive
     * already in the price), rounded to the company's price decimals.
     */
    public function total(int $decimals = 2): float
    {
        $base = (float) $this->quantity * (float) $this->price;
        $tax = (string) $this->tax_method === '1' ? $base * (float) $this->tax_percent / 100 : 0;

        return round($base + $tax, $decimals);
    }
}
