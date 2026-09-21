<?php

namespace App\Models;

use App\Traits\GeneratesMrpReference;
use Illuminate\Database\Eloquent\Model;

/**
 * An inspection against a production order.
 *
 * The status is derived from the quantities rather than chosen: a check with
 * any rejects is not "passed" no matter what someone selects in a dropdown.
 */
class MrpQualityCheck extends Model
{
    use GeneratesMrpReference;

    protected $table = 'mrp_quality_checks';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'reference', 'production_order_id', 'work_order_id', 'type', 'status',
        'qty_inspected', 'qty_passed', 'qty_rejected', 'inspector_id',
        'checked_at', 'notes', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'qty_inspected' => 'double',
        'qty_passed' => 'double',
        'qty_rejected' => 'double',
        'checked_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(MrpProductionOrder::class, 'production_order_id');
    }

    public function lines()
    {
        return $this->hasMany(MrpQualityCheckLine::class, 'quality_check_id')->orderBy('sort_order');
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /**
     * Status implied by the numbers. Called on save so the stored value can
     * never disagree with the quantities beside it.
     */
    public function deriveStatus(): string
    {
        $inspected = (float) $this->qty_inspected;
        if ($inspected <= 0) {
            return 'pending';
        }

        $rejected = (float) $this->qty_rejected;
        if ($rejected <= 0) {
            return 'passed';
        }

        return (float) $this->qty_passed > 0 ? 'partial' : 'failed';
    }

    public function passRate(): ?float
    {
        $inspected = (float) $this->qty_inspected;
        if ($inspected <= 0) {
            return null;
        }

        return round((float) $this->qty_passed / $inspected * 100, 2);
    }

    /** A failed parameter is enough to condemn the batch, whatever the counts. */
    public function hasFailedParameter(): bool
    {
        return $this->lines->contains(fn ($l) => $l->result === 'fail');
    }
}
