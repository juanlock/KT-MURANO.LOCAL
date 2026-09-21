<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of advice from a planning run: make this, or buy that.
 *
 * Every input to the arithmetic is stored alongside the answer so a planner can
 * audit the suggestion instead of taking it on faith.
 */
class MrpPlanningSuggestion extends Model
{
    protected $table = 'mrp_planning_suggestions';

    protected $fillable = [
        'planning_run_id', 'product_id', 'product_variant_id', 'warehouse_id',
        'action', 'gross_requirement', 'on_hand', 'incoming', 'safety_stock',
        'net_requirement', 'suggested_qty', 'level', 'bom_id', 'required_by',
        'status', 'created_order_id', 'notes', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'gross_requirement' => 'double',
        'on_hand' => 'double',
        'incoming' => 'double',
        'safety_stock' => 'double',
        'net_requirement' => 'double',
        'suggested_qty' => 'double',
        'level' => 'integer',
        'required_by' => 'date',
    ];

    public function run()
    {
        return $this->belongsTo(MrpPlanningRun::class, 'planning_run_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function bom()
    {
        return $this->belongsTo(MrpBom::class, 'bom_id');
    }
}
