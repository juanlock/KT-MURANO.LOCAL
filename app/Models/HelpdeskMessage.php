<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpdeskMessage extends Model
{
    protected $fillable = [
        'company_id', 'ticket_id', 'author_type', 'user_id', 'author_name', 'author_email',
        'body', 'is_internal', 'via', 'email_message_id', 'attachments',
    ];

    protected $casts = ['is_internal' => 'boolean', 'attachments' => 'array'];

    public function ticket()
    {
        return $this->belongsTo(HelpdeskTicket::class, 'ticket_id');
    }
}
