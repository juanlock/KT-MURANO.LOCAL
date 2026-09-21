<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One component of a bill of materials, quantified per BOM output. */
class MrpBomLine extends Model
{
    protected $table = 'mrp_bom_lines';

    protected $fillable = [
        'bom_id', 'product_id', 'product_variant_id', 'qty', 'unit_id',
        'scrap_pct', 'is_optional', 'notes', 'sort_order',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'qty' => 'double',
        'scrap_pct' => 'double',
        'is_optional' => 'boolean',
    ];

    public function bom()
    {
        return $this->belongsTo(MrpBom::class, 'bom_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Quantity to draw for one BOM output, scrap included.
     *
     * Scrap inflates the requirement rather than shrinking it: if 5% of a
     * component is expected to be spoiled, you must issue MORE than the net
     * need, not less.
     */
    public function qtyWithScrap(): float
    {
        return round((float) $this->qty * (1 + max(0, (float) $this->scrap_pct) / 100), 6);
    }
}
