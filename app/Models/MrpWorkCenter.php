<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A machine, cell or bench that work is booked against. Its hourly cost and
 * overhead rate are what turn minutes on the shop floor into money.
 */
class MrpWorkCenter extends Model
{
    protected $table = 'mrp_work_centers';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code', 'name', 'warehouse_id', 'capacity_per_hour', 'hourly_cost',
        'overhead_rate', 'efficiency_pct', 'description', 'is_active',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'capacity_per_hour' => 'double',
        'hourly_cost' => 'double',
        'overhead_rate' => 'double',
        'efficiency_pct' => 'integer',
        'is_active' => 'boolean',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * What a stretch of work costs here. Labour and overhead are kept apart
     * because a variance report that lumps them together cannot tell an
     * over-run on the floor from an over-absorbed burden rate.
     */
    public function costFor(float $minutes): array
    {
        $hours = max(0, $minutes) / 60;

        return [
            'labour' => round($hours * (float) $this->hourly_cost, 4),
            'overhead' => round($hours * (float) $this->overhead_rate, 4),
        ];
    }

    /**
     * Minutes this centre really needs for a nominal figure. At 80% efficiency
     * an hour of standard work takes 75 minutes, not 48 — the factor divides.
     */
    public function realMinutes(float $nominalMinutes): float
    {
        $efficiency = max(1, (int) $this->efficiency_pct);

        return round($nominalMinutes * 100 / $efficiency, 4);
    }
}
