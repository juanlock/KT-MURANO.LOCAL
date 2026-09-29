<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpdeskTicket extends Model
{
    public const STATUSES = ['open', 'pending', 'resolved', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const REQUESTERS = ['employee', 'customer', 'online_customer', 'supplier', 'guest'];

    protected $fillable = [
        'company_id', 'number', 'subject', 'status', 'priority', 'category', 'source',
        'requester_type', 'requester_user_id', 'client_id', 'provider_id', 'requester_name', 'requester_email',
        'assigned_to', 'public_token', 'last_message_at', 'first_response_at', 'resolved_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'assigned_to' => 'integer',
        'requester_user_id' => 'integer',
        'client_id' => 'integer',
        'provider_id' => 'integer',
    ];

    protected $hidden = ['public_token'];

    public function messages()
    {
        return $this->hasMany(HelpdeskMessage::class, 'ticket_id')->orderBy('id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Link for requesters without a login (public form / email). */
    public function publicUrl(): string
    {
        return url('/support/t/'.$this->public_token);
    }
}
