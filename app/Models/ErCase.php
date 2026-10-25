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
        'reference', 'patient_id', 'doctor_id', 'bed_id', 'location', 'admission_id', 'arrived_at', 'arrival_mode',
        'triage_level', 'complaint', 'temperature', 'pulse', 'bp_systolic', 'bp_diastolic',
        'respiratory_rate', 'spo2', 'pain_score', 'status', 'treatment_started_at', 'diagnosis',
        'notes', 'disposition', 'closed_at', 'disposition_notes', 'created_by',
        // Admission window + triage (2026-10-25)
        'pathway', 'triage_status', 'triage_called_at', 'triaged_at', 'triaged_by', 'weight_kg', 'height_cm', 'triage_notes',
        'usual_medication', 'registration_status', 'payer_type', 'insurer', 'policy_no', 'authorization_no', 'card_type', 'card_last4',
        'referred_by', 'procedure', 'planned_at',
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
        'triage_called_at' => 'datetime',
        'triaged_at' => 'datetime',
        'planned_at' => 'datetime',
        'weight_kg' => 'float',
        'height_cm' => 'float',
    ];

    public const PATHWAYS = ['emergency', 'critical', 'scheduled'];
    public const PAYER_TYPES = ['self_pay', 'private', 'public'];

    /**
     * The money is known (user's rule, 2026-10-25): a self-paying patient left
     * a credit card (type + last 4 digits), an insured one the insurer and the
     * policy number. Without it an emergency patient is never admitted to a
     * ward: waiting room or ER bed until sent home.
     */
    public static function financialComplete(array $d): bool
    {
        return match ($d['payer_type'] ?? null) {
            'self_pay' => ! empty($d['card_last4']),
            'private' => ! empty($d['insurer']) && ! empty($d['policy_no']),
            'public' => ! empty($d['policy_no']) || ! empty($d['insurer']),
            default => false,
        };
    }

    public function hasFinancialData(): bool
    {
        return self::financialComplete($this->only(['payer_type', 'card_last4', 'insurer', 'policy_no']));
    }

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
