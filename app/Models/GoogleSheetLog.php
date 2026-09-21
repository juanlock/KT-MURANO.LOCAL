<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleSheetLog extends Model
{
    protected $table = 'google_sheet_logs';

    protected $fillable = ['action', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];
}
