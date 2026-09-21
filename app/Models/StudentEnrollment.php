<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentEnrollment extends Model
{
    use SoftDeletes;

    protected $table = 'student_enrollments';

    protected $fillable = [
        'student_id', 'academic_year_id', 'class_id', 'section_id',
        'roll_number', 'enrolled_on', 'status', 'notes',
    ];

    protected $casts = [
        'student_id' => 'integer',
        'academic_year_id' => 'integer',
        'class_id' => 'integer',
        'section_id' => 'integer',
        'enrolled_on' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id', 'id');
    }

    public function section()
    {
        return $this->belongsTo(ClassSection::class, 'section_id', 'id');
    }

    public function getLabelAttribute()
    {
        $class = $this->schoolClass ? $this->schoolClass->name : '';
        $section = $this->section ? ' — ' . $this->section->name : '';

        return trim($class . $section);
    }
}
