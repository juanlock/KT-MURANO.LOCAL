<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A hospital shift with its hours (Hospital -> Configuration -> Shifts). */
class HmsShiftType extends Model
{
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_shift_types';

    protected $fillable = ['company_id', 'code', 'name', 'starts_at', 'hours', 'color', 'sort_order', 'is_active'];

    protected $casts = ['hours' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
}
