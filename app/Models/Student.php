<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes;

    protected $table = 'students';

    protected $fillable = [
        'admission_number', 'name', 'gender', 'date_of_birth', 'admission_date',
        'blood_group', 'phone', 'email', 'address', 'city', 'national_id', 'medical_notes',
        'guardian_name', 'guardian_relation', 'guardian_phone', 'guardian_email',
        'guardian_occupation', 'client_id', 'image', 'notes', 'status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
        'client_id' => 'integer',
    ];

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class, 'student_id', 'id');
    }

    public function attendances()
    {
        return $this->hasMany(StudentAttendance::class, 'student_id', 'id');
    }

    public function results()
    {
        return $this->hasMany(ExamResult::class, 'student_id', 'id');
    }

    public function invoices()
    {
        return $this->hasMany(SchoolInvoice::class, 'student_id', 'id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /** The enrolment for a given year, defaulting to the current one. */
    public function enrollmentFor($academicYearId = null)
    {
        $yearId = $academicYearId ?: optional(AcademicYear::current())->id;

        return StudentEnrollment::with('schoolClass', 'section')
            ->whereNull('deleted_at')
            ->where('student_id', $this->id)
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->orderBy('id', 'desc')
            ->first();
    }

    public function getAgeAttribute()
    {
        return $this->date_of_birth ? Carbon::parse($this->date_of_birth)->age : null;
    }

    /**
     * Sequential admission number, ADM-000001 upward. Not date-based: it follows
     * the student for their whole time at the school.
     */
    public static function generateAdmissionNumber()
    {
        $last = static::withTrashed()->where('admission_number', 'like', 'ADM-%')
            ->orderBy('id', 'desc')->first();
        $seq = $last ? ((int) substr($last->admission_number, 4)) + 1 : 1;

        return 'ADM-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
