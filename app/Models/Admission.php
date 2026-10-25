<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Admission extends Model
{
    use SoftDeletes, GeneratesHospitalReference, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'admissions';

    protected $fillable = [
        'reference', 'patient_id', 'doctor_id', 'ward_id', 'bed_id', 'department_id',
        'admitted_at', 'discharged_at', 'daily_rate', 'reason', 'diagnosis',
        'discharge_summary', 'status', 'created_by',
        // Administrative admission + account (2026-10-25).
        'origin', 'ordered_by', 'ordered_at', 'payer_type', 'insurer', 'policy_number', 'authorization_no', 'authorization_status',
        'coverage_percent', 'copay', 'deductible', 'consents', 'deposit_amount', 'deposit_method', 'deposit_at',
        'billing_mode', 'drg_id', 'account_invoice_id',
    ];

    public const ORIGINS = ['er', 'elective', 'outpatient', 'referral', 'transfer', 'other'];

    public const PAYERS = ['self', 'private', 'public'];

    /** The consents signed at admission (key => label). */
    public const CONSENTS = ['admission' => 'Admission authorization', 'informed' => 'Informed consent', 'rules' => 'Internal rules', 'financial' => 'Financial responsibility'];

    protected $casts = [
        'patient_id' => 'integer',
        'doctor_id' => 'integer',
        'ward_id' => 'integer',
        'bed_id' => 'integer',
        'department_id' => 'integer',
        'admitted_at' => 'datetime',
        'discharged_at' => 'datetime',
        'daily_rate' => 'decimal:2',
        'created_by' => 'integer',
        'ordered_at' => 'datetime',
        'deposit_at' => 'datetime',
        'consents' => 'array',
        'coverage_percent' => 'decimal:2',
        'copay' => 'decimal:2',
        'deductible' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'id');
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'id');
    }

    public function ward()
    {
        return $this->belongsTo(HospitalWard::class, 'ward_id', 'id');
    }

    public function bed()
    {
        return $this->belongsTo(HospitalBed::class, 'bed_id', 'id');
    }

    /**
     * Nights billed. A same-day admission still counts as one, because the bed
     * was occupied and a zero-night stay would bill nothing for it.
     */
    public function getNightsAttribute()
    {
        $from = $this->admitted_at ? Carbon::parse($this->admitted_at) : null;
        if (! $from) {
            return 0;
        }
        $to = $this->discharged_at ? Carbon::parse($this->discharged_at) : Carbon::now();

        // Cast: diffInDays returns a FLOAT here (3.0000009 for an exact
        // three-day stay), which would otherwise leak into the API and the
        // occupancy report as a fractional night.
        return max(1, (int) $from->diffInDays($to));
    }

    public function getBedChargeAttribute()
    {
        return round($this->nights * (float) $this->daily_rate, 2);
    }
}
