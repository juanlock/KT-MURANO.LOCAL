<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One emergency arrival (2026-10-21): intake -> triage -> treatment /
 * observation -> disposition. Status: waiting, in_treatment, observation,
 * closed (closed carries the disposition).
 */
class ErCase extends Model
{
    use SoftDeletes, GeneratesHospitalReference, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'er_cases';

    public const STATUSES = ['waiting', 'in_treatment', 'observation', 'closed'];
    public const DISPOSITIONS = ['discharged', 'admitted', 'transferred', 'lwbs', 'deceased'];
    public const ARRIVAL_MODES = ['walk_in', 'ambulance', 'referral', 'police', 'other'];

    protected $fillable = [
        'reference', 'patient_id', 'doctor_id', 'bed_id', 'admission_id', 'arrived_at', 'arrival_mode',
        'triage_level', 'complaint', 'temperature', 'pulse', 'bp_systolic', 'bp_diastolic',
        'respiratory_rate', 'spo2', 'pain_score', 'status', 'treatment_started_at', 'diagnosis',
        'notes', 'disposition', 'closed_at', 'disposition_notes', 'created_by',
    ];

    protected $casts = [
        'patient_id' => 'integer',
        'doctor_id' => 'integer',
        'bed_id' => 'integer',
        'admission_id' => 'integer',
        'triage_level' => 'integer',
        'arrived_at' => 'datetime',
        'treatment_started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'id');
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'id');
    }

    public function bed()
    {
        return $this->belongsTo(HospitalBed::class, 'bed_id', 'id');
    }

    public function admission()
    {
        return $this->belongsTo(Admission::class, 'admission_id', 'id');
    }
}
