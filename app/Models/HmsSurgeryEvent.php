<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HmsSurgeryEvent extends Model
{
    protected $table = 'hms_surgery_events';

    protected $fillable = ['surgery_id', 'event', 'at', 'user_id', 'notes'];

    protected $casts = ['at' => 'datetime'];
}
