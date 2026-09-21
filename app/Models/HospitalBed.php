<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HospitalBed extends Model
{
    use SoftDeletes;

    protected $table = 'hospital_beds';

    protected $fillable = ['ward_id', 'bed_number', 'status', 'daily_rate', 'notes'];

    protected $casts = [
        'ward_id' => 'integer',
        'daily_rate' => 'decimal:2',
    ];

    public function ward()
    {
        return $this->belongsTo(HospitalWard::class, 'ward_id', 'id');
    }

    public function admissions()
    {
        return $this->hasMany(Admission::class, 'bed_id', 'id');
    }

    /** The bed's own rate when set, otherwise the ward's. */
    public function getEffectiveRateAttribute()
    {
        if ($this->daily_rate !== null) {
            return (float) $this->daily_rate;
        }

        return $this->ward ? (float) $this->ward->daily_rate : 0.0;
    }
}
