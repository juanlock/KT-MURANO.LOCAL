<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailchimpLog extends Model
{
    protected $table = 'mailchimp_logs';

    protected $fillable = ['action', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];
}
