<?php

namespace App\Models;

use App\Traits\GeneratesMrpReference;
use Illuminate\Database\Eloquent\Model;

/**
 * An order to make something.
 *
 * The status ladder is draft → planned → released → in_progress → completed,
 * with cancelled reachable from anything unfinished. Only `release` moves
 * material and only `complete` moves finished goods, so the two irreversible
 * stock effects each have exactly one door.
 */
class MrpProductionOrder extends Model
{
    use GeneratesMrpReference;

    protected $table = 'mrp_production_orders';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'reference', 'bom_id', 'product_id', 'product_variant_id',
        'qty_planned', 'qty_produced', 'qty_scrapped',
        'warehouse_id', 'fg_warehouse_id', 'status', 'priority',
        'planned_start', 'planned_end', 'actual_start', 'actual_end',
        'material_cost', 'labour_cost', 'overhead_cost', 'total_cost',
        'unit_cost', 'planned_cost', 'sale_id', 'planning_run_id',
        'materials_issued', 'qc_required', 'qc_passed', 'journal_entry_id',
        'notes', 'created_by', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'qty_planned' => 'double',
        'qty_produced' => 'double',
        'qty_scrapped' => 'double',
        'material_cost' => 'double',
        'labour_cost' => 'double',
        'overhead_cost' => 'double',
        'total_cost' => 'double',
        'unit_cost' => 'double',
        'planned_cost' => 'double',
        'materials_issued' => 'boolean',
        'qc_required' => 'boolean',
        'qc_passed' => 'boolean',
        'planned_start' => 'date',
        'planned_end' => 'date',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
    ];

    /** Statuses from which an order can still be cancelled. */
    public const OPEN_STATUSES = ['draft', 'planned', 'released', 'in_progress'];

    public function bom()
    {
        return $this->belongsTo(MrpBom::class, 'bom_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function materials()
    {
        return $this->hasMany(MrpProductionOrderMaterial::class, 'production_order_id');
    }

    public function workOrders()
    {
        return $this->hasMany(MrpWorkOrder::class, 'production_order_id')->orderBy('sequence');
    }

    public function qualityChecks()
    {
        return $this->hasMany(MrpQualityCheck::class, 'production_order_id')->whereNull('deleted_at');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'cancelled'], true);
    }

    /** Good output as a share of what was planned. */
    public function yieldPct(): ?float
    {
        $planned = (float) $this->qty_planned;
        if ($planned <= 0) {
            return null;
        }

        return round((float) $this->qty_produced / $planned * 100, 2);
    }

    /** Scrap as a share of everything that came off the line. */
    public function scrapPct(): ?float
    {
        $total = (float) $this->qty_produced + (float) $this->qty_scrapped;
        if ($total <= 0) {
            return null;
        }

        return round((float) $this->qty_scrapped / $total * 100, 2);
    }

    /**
     * Actual cost against plan. Positive means it cost more than expected —
     * the direction people assume, and the opposite of the accounting sign
     * convention, so it is spelled out here rather than left to guesswork.
     */
    public function costVariance(): ?float
    {
        if ((float) $this->planned_cost <= 0) {
            return null;
        }

        return round((float) $this->total_cost - (float) $this->planned_cost, 4);
    }

    public function costVariancePct(): ?float
    {
        $planned = (float) $this->planned_cost;
        if ($planned <= 0) {
            return null;
        }

        return round(((float) $this->total_cost - $planned) / $planned * 100, 2);
    }

    /** Booked minutes across the routing, planned and actual. */
    public function minutes(): array
    {
        return [
            'planned' => round((float) $this->workOrders->sum('planned_minutes'), 2),
            'actual' => round((float) $this->workOrders->sum('actual_minutes'), 2),
        ];
    }

    /** Progress for the UI: the share of routing steps finished. */
    public function progressPct(): int
    {
        $total = $this->workOrders->count();
        if ($total === 0) {
            return in_array($this->status, ['completed'], true) ? 100 : 0;
        }

        $done = $this->workOrders->whereIn('status', ['completed', 'skipped'])->count();

        return (int) round($done / $total * 100);
    }
}
