<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsShiftPatient extends Model
{
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_shift_patients';

    protected $fillable = ['staff_shift_id', 'patient_id', 'visited_at', 'notes', 'company_id'];

    protected $casts = ['staff_shift_id' => 'integer', 'patient_id' => 'integer', 'visited_at' => 'datetime'];
}
