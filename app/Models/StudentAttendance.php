<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAttendance extends Model
{
    protected $table = 'student_attendances';

    protected $fillable = [
        'student_id', 'enrollment_id', 'class_id', 'section_id', 'subject_id',
        'attendance_date', 'status', 'remarks', 'marked_by',
    ];

    protected $casts = [
        'student_id' => 'integer',
        'enrollment_id' => 'integer',
        'class_id' => 'integer',
        'section_id' => 'integer',
        'subject_id' => 'integer',
        'attendance_date' => 'date',
        'marked_by' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }

    /**
     * Statuses that count as attending for the attendance-rate figure.
     * A half day counts as attending — the pupil was in school.
     */
    public static function presentStatuses()
    {
        return ['present', 'late', 'half_day'];
    }
}
