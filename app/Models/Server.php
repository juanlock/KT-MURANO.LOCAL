<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    protected $fillable = [
        'company_id', 'mail_mailer', 'sender_name', 'sender_email', 'host', 'port', 'username', 'password', 'encryption',
    ];

    protected $casts = [
        'port' => 'integer',
        'company_id' => 'integer',
    ];

    /**
     * Each company's own SMTP config. Unlike Setting::forCompany(), a new
     * company does NOT clone another company's row -- SMTP credentials are
     * secrets, so a company that hasn't configured its own mail server yet
     * gets a blank one to fill in, never another company's real password.
     */
    public static function forCompany(?int $companyId): self
    {
        $companyId = $companyId ?: 1;
        $existing = static::where('company_id', $companyId)->whereNull('deleted_at')->first();
        if ($existing) {
            return $existing;
        }

        return static::create([
            'company_id' => $companyId,
            'mail_mailer' => 'smtp',
            'encryption' => 'tls',
        ]);
    }
}
