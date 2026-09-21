<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimetableSlot extends Model
{
    use SoftDeletes;

    protected $table = 'timetable_slots';

    protected $fillable = [
        'academic_year_id', 'class_id', 'section_id', 'subject_id', 'teacher_id',
        'day_of_week', 'start_time', 'end_time', 'room',
    ];

    protected $casts = [
        'academic_year_id' => 'integer',
        'class_id' => 'integer',
        'section_id' => 'integer',
        'subject_id' => 'integer',
        'teacher_id' => 'integer',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'id');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id', 'id');
    }

    public function section()
    {
        return $this->belongsTo(ClassSection::class, 'section_id', 'id');
    }

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
}
