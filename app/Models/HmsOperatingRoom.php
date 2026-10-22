<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hospital: staff shifts, admission desk, treatments, operating room (2026-10-21). */
class HmsOperatingRoom extends Model
{
    use SoftDeletes, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_operating_rooms';

    protected $fillable = ['name', 'status', 'notes', 'company_id'];

    protected $casts = [];
}
