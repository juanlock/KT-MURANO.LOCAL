<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeeStructure extends Model
{
    use SoftDeletes;

    protected $table = 'fee_structures';

    protected $fillable = [
        'academic_year_id', 'class_id', 'name', 'frequency', 'amount',
        'due_date', 'is_optional', 'is_active', 'description',
    ];

    protected $casts = [
        'academic_year_id' => 'integer',
        'class_id' => 'integer',
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'is_optional' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id', 'id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'id');
    }
}
