<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XeroLog extends Model
{
    protected $table = 'xero_logs';

    protected $fillable = ['action', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];
}
