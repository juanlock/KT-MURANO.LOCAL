<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KtLog extends Model
{
    use SoftDeletes;

    protected $table = 'kt_logs';

    protected $fillable = [
        'log_date',
        'claude_user',
        'location',
        'request_user',
        'claude_understand',
        'chat_transcript',
        'files_modified',
        'actions',
        'changes_detail',
        'summary',
        'category',
        'company_context',
        'status',
        'done',
    ];

    protected $casts = [
        'log_date' => 'date',
    ];
}
