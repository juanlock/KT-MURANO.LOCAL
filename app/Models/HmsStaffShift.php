<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsStaffShift extends Model
{
    use SoftDeletes, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_staff_shifts';

    protected $fillable = ['shift_date', 'shift', 'position', 'doctor_id', 'employee_id', 'ward_id', 'notes', 'created_by', 'company_id'];

    protected $casts = ['shift_date' => 'date', 'doctor_id' => 'integer', 'employee_id' => 'integer', 'ward_id' => 'integer'];
}
