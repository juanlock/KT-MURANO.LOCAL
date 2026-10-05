<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Email Office: a company's mailbox connection (System -> Email Office -> Settings). */
class EmailOfficeSetting extends Model
{
    protected $fillable = [
        'company_id', 'enabled', 'protocol', 'host', 'port', 'encryption', 'username', 'password', 'folder',
        'import_sent', 'check_every_minutes', 'days_back', 'smtp_host', 'smtp_port', 'smtp_encryption',
        'from_name', 'from_email', 'last_polled_at', 'last_error', 'last_stats', 'full_import',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'enabled' => 'boolean',
        'import_sent' => 'boolean',
        'port' => 'integer',
        'smtp_port' => 'integer',
        'check_every_minutes' => 'integer',
        'days_back' => 'integer',
        'password' => 'encrypted',
        'last_polled_at' => 'datetime',
        'last_stats' => 'array',
        'full_import' => 'array',
    ];

    protected $hidden = ['password'];

    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(['company_id' => $companyId], [
            'protocol' => 'imap', 'encryption' => 'ssl', 'folder' => 'INBOX', 'import_sent' => true,
            'check_every_minutes' => 15, 'days_back' => 30,
        ]);
    }

    /** The mailbox's own address (lower case), or null. */
    public function address(): ?string
    {
        $a = strtolower(trim((string) ($this->from_email ?: $this->username)));

        return $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : null;
    }

    /** Due for an automatic check (enabled, set up, interval passed). */
    public function isDue(): bool
    {
        return $this->enabled && $this->host && $this->username && $this->password
            && (! $this->last_polled_at || $this->last_polled_at->lte(now()->subMinutes(max(1, (int) $this->check_every_minutes))));
    }
}
