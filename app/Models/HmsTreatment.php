<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsTreatment extends Model
{
    use SoftDeletes, GeneratesHospitalReference, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_treatments';

    protected $fillable = ['reference', 'patient_id', 'admission_id', 'er_case_id', 'surgery_id', 'lead_doctor_id', 'diagnosis', 'plan', 'status', 'started_at', 'ended_at', 'created_by', 'company_id'];

    protected $casts = ['patient_id' => 'integer', 'admission_id' => 'integer', 'er_case_id' => 'integer', 'surgery_id' => 'integer', 'lead_doctor_id' => 'integer', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
}
