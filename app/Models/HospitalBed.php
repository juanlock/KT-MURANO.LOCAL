<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HospitalBed extends Model
{
    use SoftDeletes, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hospital_beds';

    /**
     * available = free; occupied = a patient in it (admissions / ER only);
     * cleaning = freed by a discharge / transfer / ER close, waiting for the
     * cleaning staff (not assignable until marked clean); reserved = held for an
     * incoming patient; maintenance = out of service. (cleaning: 2026-10-25.)
     */
    public const STATUSES = ['available', 'occupied', 'cleaning', 'reserved', 'maintenance'];

    protected $fillable = ['ward_id', 'room_id', 'bed_number', 'status', 'status_changed_at', 'map_x', 'map_y', 'daily_rate', 'notes'];

    protected $casts = [
        'ward_id' => 'integer',
        'room_id' => 'integer',
        'map_x' => 'integer',
        'map_y' => 'integer',
        'daily_rate' => 'decimal:2',
        'status_changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Every status change is timed (the board shows how long a bed waits for cleaning).
        static::saving(function (HospitalBed $bed) {
            if ($bed->isDirty('status')) {
                $bed->status_changed_at = now();
            }
        });
    }

    /**
     * A patient left the bed (discharge, transfer, ER case closed): it goes to
     * cleaning, never straight to available. Only an occupied bed is touched.
     */
    public static function release($bedId): void
    {
        if ($bedId) {
            static::withoutGlobalScope('company')->where('id', $bedId)->where('status', 'occupied')
                ->update(['status' => 'cleaning', 'status_changed_at' => now()]);
        }
    }

    public function room()
    {
        return $this->belongsTo(HmsRoom::class, 'room_id');
    }

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
