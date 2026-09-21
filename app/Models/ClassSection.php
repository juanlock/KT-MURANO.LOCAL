<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassSection extends Model
{
    use SoftDeletes;

    protected $table = 'class_sections';

    protected $fillable = ['class_id', 'name', 'capacity', 'room', 'teacher_id', 'is_active'];

    protected $casts = [
        'class_id' => 'integer',
        'teacher_id' => 'integer',
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id', 'id');
    }

    /** Form teacher. */
    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'id');
    }

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class, 'section_id', 'id');
    }
}
