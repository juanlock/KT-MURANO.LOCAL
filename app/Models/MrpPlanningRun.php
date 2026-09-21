<?php

namespace App\Models;

use App\Traits\GeneratesMrpReference;
use Illuminate\Database\Eloquent\Model;

/** One execution of the planning engine. */
class MrpPlanningRun extends Model
{
    use GeneratesMrpReference;

    protected $table = 'mrp_planning_runs';

    protected $fillable = [
        'reference', 'warehouse_id', 'horizon_start', 'horizon_end', 'status',
        'demand_lines', 'make_suggestions', 'buy_suggestions',
        'include_safety_stock', 'last_error', 'created_by',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'horizon_start' => 'date',
        'horizon_end' => 'date',
        'include_safety_stock' => 'boolean',
    ];

    public function suggestions()
    {
        return $this->hasMany(MrpPlanningSuggestion::class, 'planning_run_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
