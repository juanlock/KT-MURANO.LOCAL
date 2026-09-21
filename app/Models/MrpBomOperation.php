<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One routing step: what is done, where, and how long it should take. */
class MrpBomOperation extends Model
{
    protected $table = 'mrp_bom_operations';

    protected $fillable = [
        'bom_id', 'sequence', 'name', 'work_center_id', 'setup_minutes',
        'run_minutes_per_unit', 'requires_qc', 'instructions',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'setup_minutes' => 'double',
        'run_minutes_per_unit' => 'double',
        'requires_qc' => 'boolean',
    ];

    public function bom()
    {
        return $this->belongsTo(MrpBom::class, 'bom_id');
    }

    public function workCenter()
    {
        return $this->belongsTo(MrpWorkCenter::class, 'work_center_id');
    }

    /**
     * Minutes for a given batch. Setup is charged once for the run, not per
     * unit — that is the whole reason bigger batches cost less each.
     */
    public function minutesFor(float $qty): float
    {
        return round((float) $this->setup_minutes + ((float) $this->run_minutes_per_unit * max(0, $qty)), 4);
    }
}
