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
}
