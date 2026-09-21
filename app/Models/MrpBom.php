<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A bill of materials: what a product is made of and how it is made.
 *
 * Component quantities are stated per `output_qty`, not per single unit, so a
 * recipe that yields 12 reads the way the person who wrote it thinks. Every
 * consumer scales through {@see requirementsFor()} rather than doing that
 * arithmetic itself, which is what stops a "per batch" figure being mistaken
 * for a "per unit" one somewhere downstream.
 */
class MrpBom extends Model
{
    protected $table = 'mrp_boms';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code', 'name', 'product_id', 'product_variant_id', 'output_qty',
        'unit_id', 'warehouse_id', 'version', 'status', 'is_default',
        'scrap_pct', 'overhead_cost', 'notes', 'created_by',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'output_qty' => 'double',
        'scrap_pct' => 'double',
        'overhead_cost' => 'double',
        'version' => 'integer',
        'is_default' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function lines()
    {
        return $this->hasMany(MrpBomLine::class, 'bom_id')->orderBy('sort_order');
    }

    public function operations()
    {
        return $this->hasMany(MrpBomOperation::class, 'bom_id')->orderBy('sequence');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** The BOM to use for a product when none was named explicitly. */
    public static function defaultFor($productId, $variantId = null): ?self
    {
        return static::whereNull('deleted_at')
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId))
            ->orderByDesc('is_default')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * How many runs of this recipe are needed to yield a quantity.
     *
     * Rounded up: you cannot make three-fifths of a batch, and rounding down
     * would quietly plan a shortfall.
     */
    public function runsFor(float $qty): float
    {
        $output = (float) $this->output_qty;
        if ($output <= 0) {
            return 0.0;
        }

        return ceil(round($qty / $output, 6));
    }

    /**
     * Component requirements to produce a quantity of the finished item.
     *
     * Two scrap allowances apply and they compound, because they describe
     * different losses: the BOM's own scrap_pct is output you expect to lose
     * (so you must start more), and each line's scrap_pct is component you
     * expect to spoil (so you must draw more of that component).
     *
     * @return array<int, array{product_id:int, product_variant_id:?int, qty:float, unit_id:?int, is_optional:bool}>
     */
    public function requirementsFor(float $qty, bool $includeOptional = true): array
    {
        if ($qty <= 0) {
            return [];
        }

        $output = (float) $this->output_qty;
        if ($output <= 0) {
            return [];
        }

        // Start extra so that, after the expected output loss, `qty` survives.
        $scrap = max(0, min(99.9, (float) $this->scrap_pct));
        $grossQty = $scrap > 0 ? $qty / (1 - $scrap / 100) : $qty;
        $factor = $grossQty / $output;

        $rows = [];
        foreach ($this->lines as $line) {
            if (! $includeOptional && $line->is_optional) {
                continue;
            }

            $rows[] = [
                'product_id' => (int) $line->product_id,
                'product_variant_id' => $line->product_variant_id ? (int) $line->product_variant_id : null,
                'qty' => round($line->qtyWithScrap() * $factor, 6),
                'unit_id' => $line->unit_id,
                'is_optional' => (bool) $line->is_optional,
            ];
        }

        return $rows;
    }

    /** Routing steps sized for a batch, with the cost each implies. */
    public function operationPlanFor(float $qty): array
    {
        $plan = [];
        foreach ($this->operations as $operation) {
            $nominal = $operation->minutesFor($qty);
            $centre = $operation->workCenter;
            $minutes = $centre ? $centre->realMinutes($nominal) : $nominal;
            $cost = $centre ? $centre->costFor($minutes) : ['labour' => 0.0, 'overhead' => 0.0];

            $plan[] = [
                'bom_operation_id' => (int) $operation->id,
                'sequence' => (int) $operation->sequence,
                'name' => $operation->name,
                'work_center_id' => $operation->work_center_id,
                'requires_qc' => (bool) $operation->requires_qc,
                'planned_minutes' => $minutes,
                'labour_cost' => $cost['labour'],
                'overhead_cost' => $cost['overhead'],
            ];
        }

        return $plan;
    }

    /** Does any routing step demand an inspection? */
    public function requiresQc(): bool
    {
        return $this->operations->contains(fn ($o) => (bool) $o->requires_qc);
    }
}
