<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use SoftDeletes;

    protected $table = 'academic_years';

    protected $fillable = ['name', 'start_date', 'end_date', 'is_current', 'is_locked'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class, 'academic_year_id', 'id');
    }

    /** The year everything defaults to; falls back to the newest on file. */
    public static function current()
    {
        return static::whereNull('deleted_at')->where('is_current', 1)->first()
            ?: static::whereNull('deleted_at')->orderBy('start_date', 'desc')->first();
    }
}
