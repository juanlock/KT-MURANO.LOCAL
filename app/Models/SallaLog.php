<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SallaLog extends Model
{
    protected $table = 'salla_logs';

    protected $fillable = ['action', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];
}
