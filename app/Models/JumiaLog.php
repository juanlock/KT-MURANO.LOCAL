<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JumiaLog extends Model
{
    protected $table = 'jumia_logs';

    protected $fillable = ['action', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];
}
