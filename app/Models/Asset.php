<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'tag',
        'name',
        'asset_category_id',
        'serial_number',
        'description',
        'purchase_date',
        'purchase_cost',
        'supplier',
        'warranty_expiry',
        'depreciation_method',
        'useful_life_months',
        'salvage_value',
        'disposal_date',
        'disposal_amount',
        'disposal_note',
        'status',
        'warehouse_id',
        'assigned_to_id',
        'last_verification',
        'next_validation',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'purchase_cost' => 'double',
        'salvage_value' => 'double',
        'disposal_amount' => 'double',
        'useful_life_months' => 'integer',
        'last_verification' => 'date',
        'next_validation' => 'date',
        'warranty_expiry' => 'date',
        'disposal_date' => 'date',
    ];

    public function assetCategory()
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function holder()
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function assignments()
    {
        return $this->hasMany(AssetAssignment::class)->whereNull('deleted_at');
    }

    public function maintenances()
    {
        return $this->hasMany(AssetMaintenance::class)->whereNull('deleted_at');
    }

    public function transfers()
    {
        return $this->hasMany(AssetTransfer::class)->whereNull('deleted_at');
    }

    /** The custody record that has not been handed back yet, if any. */
    public function openAssignment()
    {
        return $this->hasOne(AssetAssignment::class)
            ->whereNull('deleted_at')
            ->where('status', 'assigned');
    }

    // ---------------------------------------------------------------- money --

    /**
     * Months of depreciation elapsed, capped at the useful life and stopped at
     * the disposal date — a sold asset does not keep losing value on our books.
     */
    public function monthsDepreciated($asOf = null): int
    {
        if (! $this->purchase_date || ! $this->useful_life_months) {
            return 0;
        }

        $asOf = $asOf ? Carbon::parse($asOf) : Carbon::today();
        if ($this->disposal_date && $this->disposal_date->lt($asOf)) {
            $asOf = $this->disposal_date->copy();
        }

        $start = Carbon::parse($this->purchase_date);
        if ($asOf->lte($start)) {
            return 0;
        }

        // diffInMonths returns a float on this Carbon version; the schedule is
        // monthly, so anything short of a full month has not accrued yet.
        $elapsed = (int) floor($start->diffInMonths($asOf));

        return max(0, min($elapsed, (int) $this->useful_life_months));
    }

    /** The part of the cost that is allowed to be written off over the life. */
    public function depreciableBase(): float
    {
        return max(0, (float) $this->purchase_cost - (float) $this->salvage_value);
    }

    /**
     * Accumulated depreciation to date.
     *
     * Straight line spreads the base evenly; declining balance writes off twice
     * the straight-line rate on the remaining value each month, which front-loads
     * the loss the way IT equipment actually behaves. Either way the result is
     * clamped so book value can never fall below salvage.
     */
    public function accumulatedDepreciation($asOf = null): float
    {
        $method = $this->depreciation_method ?: 'none';
        if ($method === 'none' || ! $this->purchase_cost || ! $this->useful_life_months) {
            return 0.0;
        }

        $months = $this->monthsDepreciated($asOf);
        if ($months <= 0) {
            return 0.0;
        }

        $base = $this->depreciableBase();
        $life = (int) $this->useful_life_months;

        if ($method === 'declining_balance') {
            $rate = 2 / $life;                 // double-declining, per month
            $value = (float) $this->purchase_cost;
            $floor = (float) $this->salvage_value;
            for ($i = 0; $i < $months; $i++) {
                $step = $value * $rate;
                if ($value - $step < $floor) {
                    $step = $value - $floor;
                }
                $value -= max(0, $step);
            }

            return round((float) $this->purchase_cost - $value, 2);
        }

        return round(min($base, $base * ($months / $life)), 2);
    }

    /** What the asset is worth on the books today. */
    public function bookValue($asOf = null): float
    {
        return round(max(0, (float) $this->purchase_cost - $this->accumulatedDepreciation($asOf)), 2);
    }

    /** Positive = sold above book value. Null until the asset is disposed of. */
    public function disposalGain(): ?float
    {
        if (! $this->disposal_date) {
            return null;
        }

        return round((float) $this->disposal_amount - $this->bookValue($this->disposal_date), 2);
    }

    /**
     * Year-by-year schedule for the depreciation page: opening value, the
     * charge for that year and the closing value.
     */
    public function depreciationSchedule(): array
    {
        $method = $this->depreciation_method ?: 'none';
        if ($method === 'none' || ! $this->purchase_date || ! $this->useful_life_months || ! $this->purchase_cost) {
            return [];
        }

        $start = Carbon::parse($this->purchase_date);
        $life = (int) $this->useful_life_months;
        $years = (int) ceil($life / 12);
        $rows = [];
        $previous = 0.0;

        for ($y = 1; $y <= $years; $y++) {
            $asOf = $start->copy()->addMonths(min($y * 12, $life));
            $accumulated = $this->accumulatedDepreciation($asOf);
            $rows[] = [
                'year' => $y,
                'period' => $start->copy()->addMonths(($y - 1) * 12)->format('Y-m-d').' → '.$asOf->format('Y-m-d'),
                'opening' => round((float) $this->purchase_cost - $previous, 2),
                'depreciation' => round($accumulated - $previous, 2),
                'accumulated' => $accumulated,
                'closing' => round((float) $this->purchase_cost - $accumulated, 2),
            ];
            $previous = $accumulated;
        }

        return $rows;
    }

    // --------------------------------------------------------------- health --

    /** Days until the next validation is due; negative once it is overdue. */
    public function daysToValidation(): ?int
    {
        if (! $this->next_validation) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->next_validation, false);
    }

    public function isUnderWarranty(): bool
    {
        return $this->warranty_expiry ? $this->warranty_expiry->gte(Carbon::today()) : false;
    }
}
