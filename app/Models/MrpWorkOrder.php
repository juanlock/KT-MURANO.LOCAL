<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One routing step being executed on the shop floor.
 *
 * Actual minutes are measured from the clock where possible rather than typed,
 * because a hand-entered duration is the first number to drift and it feeds
 * straight into labour cost.
 */
class MrpWorkOrder extends Model
{
    protected $table = 'mrp_work_orders';

    protected $fillable = [
        'production_order_id', 'bom_operation_id', 'sequence', 'name',
        'work_center_id', 'employee_id', 'status', 'planned_minutes',
        'actual_minutes', 'qty_completed', 'qty_rejected',
        'started_at', 'finished_at', 'labour_cost', 'overhead_cost',
        'requires_qc', 'notes', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'planned_minutes' => 'double',
        'actual_minutes' => 'double',
        'qty_completed' => 'double',
        'qty_rejected' => 'double',
        'labour_cost' => 'double',
        'overhead_cost' => 'double',
        'requires_qc' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(MrpProductionOrder::class, 'production_order_id');
    }

    public function workCenter()
    {
        return $this->belongsTo(MrpWorkCenter::class, 'work_center_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** Minutes actually elapsed, when the step was clocked in and out. */
    public function elapsedMinutes(): ?float
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return round($this->started_at->diffInSeconds($this->finished_at) / 60, 2);
    }

    /**
     * Time taken against time allowed. Over 100 means it ran long, which is the
     * reading a supervisor expects from the word "over".
     */
    public function timeVariancePct(): ?float
    {
        $planned = (float) $this->planned_minutes;
        if ($planned <= 0) {
            return null;
        }

        return round(((float) $this->actual_minutes - $planned) / $planned * 100, 2);
    }

    /**
     * Labour and overhead for this step.
     *
     * Labour prefers the assigned employee's own hourly rate and falls back to
     * the work centre's — an operator's real cost beats a nominal cell rate
     * whenever it is known. Overhead always comes from the centre, since it
     * belongs to the machine, not the person.
     */
    public function computeCosts(): array
    {
        $minutes = max(0, (float) $this->actual_minutes);
        $hours = $minutes / 60;

        $centre = $this->workCenter;
        $employee = $this->employee_id ? $this->employee : null;

        $rate = $employee && (float) $employee->hourly_rate > 0
            ? (float) $employee->hourly_rate
            : ($centre ? (float) $centre->hourly_cost : 0.0);

        return [
            'labour' => round($hours * $rate, 4),
            'overhead' => round($hours * ($centre ? (float) $centre->overhead_rate : 0.0), 4),
        ];
    }
}
