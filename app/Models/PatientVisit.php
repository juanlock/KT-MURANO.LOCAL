<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientVisit extends Model
{
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'patient_visits';

    protected $fillable = [
        'reference', 'patient_id', 'doctor_id', 'appointment_id', 'department_id',
        'visit_date', 'type', 'temperature', 'pulse', 'bp_systolic', 'bp_diastolic',
        'respiratory_rate', 'spo2', 'weight', 'height', 'complaint', 'examination',
        'diagnosis', 'treatment_plan', 'follow_up_date', 'status', 'fee', 'created_by',
    ];

    protected $casts = [
        'patient_id' => 'integer',
        'doctor_id' => 'integer',
        'appointment_id' => 'integer',
        'department_id' => 'integer',
        'visit_date' => 'datetime',
        'follow_up_date' => 'date',
        'temperature' => 'decimal:2',
        'weight' => 'decimal:2',
        'height' => 'decimal:2',
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

    public function appointment()
    {
        return $this->belongsTo(Appointment::class, 'appointment_id', 'id');
    }

    public function prescriptions()
    {
        return $this->hasMany(Prescription::class, 'visit_id', 'id');
    }

    public function labOrders()
    {
        return $this->hasMany(LabOrder::class, 'visit_id', 'id');
    }

    /** "120/80", or null unless both halves were recorded. */
    public function getBloodPressureAttribute()
    {
        if (! $this->bp_systolic || ! $this->bp_diastolic) {
            return null;
        }

        return $this->bp_systolic . '/' . $this->bp_diastolic;
    }

    /** Height is stored in cm; BMI needs metres. */
    public function getBmiAttribute()
    {
        $height = (float) $this->height;
        $weight = (float) $this->weight;

        if ($height <= 0 || $weight <= 0) {
            return null;
        }

        return round($weight / (($height / 100) ** 2), 1);
    }
}
