<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSubject extends Model
{
    protected $table = 'exam_subjects';

    protected $fillable = [
        'exam_id', 'class_id', 'subject_id', 'exam_date', 'start_time',
        'duration_minutes', 'max_marks', 'pass_marks', 'room',
    ];

    protected $casts = [
        'exam_id' => 'integer',
        'class_id' => 'integer',
        'subject_id' => 'integer',
        'exam_date' => 'date',
        'duration_minutes' => 'integer',
        'max_marks' => 'decimal:2',
        'pass_marks' => 'decimal:2',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id', 'id');
    }

    public function results()
    {
        return $this->hasMany(ExamResult::class, 'exam_subject_id', 'id');
    }
}
