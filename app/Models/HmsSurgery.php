<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsSurgery extends Model
{
    use SoftDeletes, GeneratesHospitalReference, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_surgeries';

    protected $fillable = ['reference', 'patient_id', 'admission_id', 'operating_room_id', 'surgeon_id', 'anesthetist_id', 'team', 'scheduled_at', 'estimated_minutes', 'status', 'priority', 'pre_op_diagnosis', 'planned_procedure', 'risks', 'consent_signed', 'procedure_performed', 'anesthesia', 'started_at', 'ended_at', 'findings', 'complications', 'post_op_diagnosis', 'post_op_instructions', 'fee', 'created_by', 'company_id'];

    protected $casts = ['patient_id' => 'integer', 'admission_id' => 'integer', 'operating_room_id' => 'integer', 'surgeon_id' => 'integer', 'anesthetist_id' => 'integer', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'consent_signed' => 'boolean', 'fee' => 'float'];
}
