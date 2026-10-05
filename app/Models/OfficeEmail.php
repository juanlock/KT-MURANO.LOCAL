<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Email Office: one email, received (direction in) or sent (out) -- table
 * office_emails. Not to be confused with EmailMessage (email TEMPLATES).
 * See App\Services\EmailOffice\EmailOffice for how it's sorted and matched.
 */
class OfficeEmail extends Model
{
    protected $table = 'office_emails';

    public const CATEGORIES = ['bill', 'claim', 'reminder', 'general'];

    protected $fillable = [
        'company_id', 'direction', 'source', 'message_id', 'message_hash', 'in_reply_to', 'subject',
        'from_email', 'from_name', 'to_emails', 'cc_emails', 'sent_at', 'body_text', 'body_html', 'snippet',
        'attachments', 'category', 'category_manual', 'party_type', 'party_id', 'linked_type', 'linked_id',
        'is_read', 'status', 'error',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'attachments' => 'array',
        'category_manual' => 'boolean',
        'party_id' => 'integer',
        'linked_id' => 'integer',
        'is_read' => 'boolean',
        'sent_at' => 'datetime',
    ];
}
