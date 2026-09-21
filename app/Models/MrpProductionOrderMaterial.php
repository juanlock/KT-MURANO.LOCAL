<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A component line on a production order: what was needed, what actually left
 * the store, and what it cost at the moment it left.
 */
class MrpProductionOrderMaterial extends Model
{
    protected $table = 'mrp_production_order_materials';

    protected $fillable = [
        'production_order_id', 'product_id', 'product_variant_id',
        'qty_required', 'qty_issued', 'qty_returned', 'unit_id',
        'unit_cost', 'total_cost', 'is_optional',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'qty_required' => 'double',
        'qty_issued' => 'double',
        'qty_returned' => 'double',
        'unit_cost' => 'double',
        'total_cost' => 'double',
        'is_optional' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(MrpProductionOrder::class, 'production_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Issued less anything handed back — the quantity actually consumed. */
    public function qtyConsumed(): float
    {
        return round((float) $this->qty_issued - (float) $this->qty_returned, 6);
    }

    public function shortfall(): float
    {
        return round(max(0, (float) $this->qty_required - $this->qtyConsumed()), 6);
    }
}
