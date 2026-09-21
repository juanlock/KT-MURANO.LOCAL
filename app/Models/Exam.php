<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exam extends Model
{
    use SoftDeletes;

    protected $table = 'exams';

    protected $fillable = [
        'academic_year_id', 'name', 'term', 'start_date', 'end_date', 'status', 'notes',
    ];

    protected $casts = [
        'academic_year_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'id');
    }

    public function examSubjects()
    {
        return $this->hasMany(ExamSubject::class, 'exam_id', 'id');
    }
}
