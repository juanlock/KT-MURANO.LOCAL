<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsVisitor extends Model
{
    use SoftDeletes, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_visitors';

    protected $fillable = ['patient_id', 'name', 'document', 'relation', 'phone', 'checked_in_at', 'checked_out_at', 'badge', 'notes', 'created_by', 'company_id'];

    protected $casts = ['patient_id' => 'integer', 'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime'];
}
