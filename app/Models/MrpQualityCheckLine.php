<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One measured parameter on an inspection. */
class MrpQualityCheckLine extends Model
{
    protected $table = 'mrp_quality_check_lines';

    protected $fillable = [
        'quality_check_id', 'parameter', 'expected', 'actual', 'result',
        'notes', 'sort_order', 'created_at', 'updated_at',
    ];

    public function check()
    {
        return $this->belongsTo(MrpQualityCheck::class, 'quality_check_id');
    }
}
