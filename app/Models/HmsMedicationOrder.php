<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsMedicationOrder extends Model
{
    use SoftDeletes, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_medication_orders';

    protected $fillable = ['treatment_id', 'product_id', 'medicine', 'dose', 'route', 'every_hours', 'starts_at', 'days', 'units_per_dose', 'instructions', 'status', 'doctor_id', 'created_by', 'company_id'];

    protected $casts = ['treatment_id' => 'integer', 'product_id' => 'integer', 'every_hours' => 'integer', 'days' => 'integer', 'units_per_dose' => 'float', 'starts_at' => 'datetime'];
}
