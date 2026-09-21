<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'appointments';

    protected $fillable = [
        'reference', 'patient_id', 'doctor_id', 'department_id', 'scheduled_at',
        'duration_minutes', 'type', 'status', 'reason', 'fee', 'notes', 'created_by',
    ];

    protected $casts = [
        'patient_id' => 'integer',
        'doctor_id' => 'integer',
        'department_id' => 'integer',
        'scheduled_at' => 'datetime',
        'duration_minutes' => 'integer',
        'fee' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'id');
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'id');
    }

    public function department()
    {
        return $this->belongsTo(HospitalDepartment::class, 'department_id', 'id');
    }

    public function visit()
    {
        return $this->hasOne(PatientVisit::class, 'appointment_id', 'id');
    }

    /** Statuses that still hold a slot in the doctor's diary. */
    public static function blockingStatuses()
    {
        return ['scheduled', 'confirmed', 'arrived'];
    }
}
