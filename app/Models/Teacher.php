<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use SoftDeletes;

    protected $table = 'teachers';

    protected $fillable = [
        'employee_code', 'name', 'employee_id', 'gender', 'phone', 'email',
        'qualification', 'specialization', 'joining_date', 'salary', 'address',
        'image', 'notes', 'is_active',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'joining_date' => 'date',
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    /** Sections where this teacher is the form teacher. */
    public function sections()
    {
        return $this->hasMany(ClassSection::class, 'teacher_id', 'id');
    }

    public function timetableSlots()
    {
        return $this->hasMany(TimetableSlot::class, 'teacher_id', 'id');
    }
}
