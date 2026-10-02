<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An invoice (Sale) generated from a sales contract, with the period it bills. */
class SalesContractInvoice extends Model
{
    protected $fillable = ['sales_contract_id', 'sale_id', 'period_start', 'period_end', 'automatic'];

    protected $casts = ['automatic' => 'boolean'];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
