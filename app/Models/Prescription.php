<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prescription extends Model
{
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'prescriptions';

    protected $fillable = [
        'reference', 'visit_id', 'patient_id', 'doctor_id', 'prescribed_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'visit_id' => 'integer',
        'patient_id' => 'integer',
        'doctor_id' => 'integer',
        'prescribed_on' => 'date',
        'created_by' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(PrescriptionItem::class, 'prescription_id', 'id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'id');
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'id');
    }

    public function visit()
    {
        return $this->belongsTo(PatientVisit::class, 'visit_id', 'id');
    }
}
