<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Admission extends Model
{
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'admissions';

    protected $fillable = [
        'reference', 'patient_id', 'doctor_id', 'ward_id', 'bed_id', 'department_id',
        'admitted_at', 'discharged_at', 'daily_rate', 'reason', 'diagnosis',
        'discharge_summary', 'status', 'created_by',
    ];

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
