<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabTest extends Model
{
    use SoftDeletes;

    protected $table = 'lab_tests';

    protected $fillable = [
        'name', 'code', 'category', 'sample_type', 'unit', 'normal_range',
        'price', 'turnaround_hours', 'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'turnaround_hours' => 'integer',
        'is_active' => 'boolean',
    ];
}
