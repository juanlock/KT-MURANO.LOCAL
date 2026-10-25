<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsMedicationDose extends Model
{
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_medication_doses';

    protected $fillable = ['order_id', 'treatment_id', 'patient_id', 'scheduled_at', 'status', 'given_at', 'given_by', 'dispensed', 'notes', 'company_id'];

    protected $casts = ['order_id' => 'integer', 'treatment_id' => 'integer', 'patient_id' => 'integer', 'scheduled_at' => 'datetime', 'given_at' => 'datetime', 'dispensed' => 'boolean'];

    /**
     * Only today's doses can be recorded (user's rule, 2026-10-25): not the ones
     * of tomorrow or later, and not those of past days (they are "missed").
     */
    public function recordableToday(): bool
    {
        return $this->scheduled_at && $this->scheduled_at->isSameDay(now()) && $this->status !== 'missed';
    }

    /**
     * Doses of past days nobody recorded become "missed" (not given, no nurse
     * registered it). Runs when the medication sheet / kardex is opened and
     * every night (hospital:mark-missed-doses). Returns how many.
     */
    public static function markMissed(): int
    {
        return static::withoutGlobalScope('company')->where('status', 'pending')->where('scheduled_at', '<', now()->startOfDay())
            ->update(['status' => 'missed', 'notes' => 'Not recorded by nursing', 'updated_at' => now()]);
    }
}
