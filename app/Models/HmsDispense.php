<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsDispense extends Model
{
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_dispenses';

    protected $fillable = ['order_id', 'product_id', 'warehouse_id', 'quantity', 'doses', 'dispensed_at', 'dispensed_by', 'notes', 'company_id'];

    protected $casts = ['order_id' => 'integer', 'quantity' => 'float', 'doses' => 'integer', 'dispensed_at' => 'datetime'];
}
