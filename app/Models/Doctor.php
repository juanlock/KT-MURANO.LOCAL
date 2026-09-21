<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Doctor extends Model
{
    use SoftDeletes;

    protected $table = 'doctors';

    protected $fillable = [
        'name', 'code', 'department_id', 'employee_id', 'specialty', 'qualification',
        'license_no', 'phone', 'email', 'consultation_fee', 'availability', 'image',
        'is_active', 'notes',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'employee_id' => 'integer',
        'consultation_fee' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function department()
    {
        return $this->belongsTo(HospitalDepartment::class, 'department_id', 'id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'doctor_id', 'id');
    }

    public function visits()
    {
        return $this->hasMany(PatientVisit::class, 'doctor_id', 'id');
    }

    /** Weekly schedule as {day: [from, to]}; always an array for the caller. */
    public function getAvailabilityListAttribute()
    {
        $decoded = json_decode((string) $this->availability, true);

        return is_array($decoded) ? $decoded : [];
    }
}
