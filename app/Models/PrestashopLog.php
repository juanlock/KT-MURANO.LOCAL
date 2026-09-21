<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrestashopLog extends Model
{
    protected $table = 'prestashop_logs';

    protected $fillable = ['action', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];
}
