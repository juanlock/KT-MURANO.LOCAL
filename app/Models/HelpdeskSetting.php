<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpdeskSetting extends Model
{
    protected $fillable = [
        'company_id', 'ticket_prefix', 'public_form_enabled', 'auto_reply', 'categories',
        'imap_enabled', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password', 'imap_folder',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'from_email', 'from_name', 'last_polled_at', 'last_error',
    ];

    protected $casts = [
        'public_form_enabled' => 'boolean',
        'auto_reply' => 'boolean',
        'imap_enabled' => 'boolean',
        'imap_port' => 'integer',
        'smtp_port' => 'integer',
        'categories' => 'array',
        'imap_password' => 'encrypted',
        'last_polled_at' => 'datetime',
    ];

    protected $hidden = ['imap_password'];

    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(['company_id' => $companyId], [
            'ticket_prefix' => 'HD-', 'public_form_enabled' => true, 'auto_reply' => true,
            'categories' => ['General', 'Billing', 'Technical', 'Orders'], 'imap_folder' => 'INBOX',
        ]);
    }

    /** The helpdesk's own address (replies come back here). */
    public function address(): ?string
    {
        $a = $this->from_email ?: $this->imap_username;

        return $a && filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : null;
    }
}
