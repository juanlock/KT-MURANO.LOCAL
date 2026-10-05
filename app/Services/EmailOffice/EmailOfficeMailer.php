<?php

namespace App\Services\EmailOffice;

use App\Models\EmailOfficeSetting;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;

/**
 * Email Office -> Email sent -> New email. Sends through the company's Email
 * Office SMTP (Settings, same login as the mailbox) when set, else the
 * company's mail server (Settings -> Mail), else the install default. The
 * X-Email-Office-* headers let LogSentEmail file it under this company as
 * "compose" (one row per email: the Sent-folder import finds the same
 * Message-ID and skips it).
 */
class EmailOfficeMailer
{
    public static function send(int $companyId, array $to, array $cc, string $subject, string $body): void
    {
        $s = EmailOfficeSetting::forCompany($companyId);
        [$mailer, $fromEmail, $fromName] = self::mailer($companyId, $s);
        abort_unless($fromEmail, 422, 'No sender address: set "From email" in Email Office settings or the company mail server.');

        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222">'.nl2br(e($body)).'</div>';
        $mailer->html($html, function ($m) use ($to, $cc, $subject, $body, $fromEmail, $fromName, $companyId) {
            $m->to($to)->from($fromEmail, $fromName)->subject($subject);
            if ($cc) {
                $m->cc($cc);
            }
            $m->getSymfonyMessage()->text($body);
            $h = $m->getSymfonyMessage()->getHeaders();
            $h->addTextHeader('X-Email-Office-Company', (string) $companyId);
            $h->addTextHeader('X-Email-Office-Source', 'compose');
        });
    }

    /** [mailer, from email, from name] */
    private static function mailer(int $companyId, EmailOfficeSetting $s): array
    {
        $company = Setting::forCompany($companyId)->CompanyName ?? null;
        if ($s->smtp_host && $s->username && $s->password) {
            $port = (int) ($s->smtp_port ?: 587);

            return [Mail::build([
                'transport' => 'smtp', 'host' => $s->smtp_host, 'port' => $port,
                'scheme' => ($s->smtp_encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
                'username' => $s->username, 'password' => $s->password, 'timeout' => 20,
            ]), $s->address(), $s->from_name ?: $company];
        }
        $srv = Server::forCompany($companyId);
        if ($srv && $srv->host) {
            $port = (int) ($srv->port ?: 587);

            return [Mail::build([
                'transport' => 'smtp', 'host' => $srv->host, 'port' => $port,
                'scheme' => ($srv->encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
                'username' => $srv->username, 'password' => $srv->password, 'timeout' => 20,
            ]), $s->from_email ?: ($srv->sender_email ?: config('mail.from.address')), $s->from_name ?: ($srv->sender_name ?: $company)];
        }

        return [Mail::mailer(), $s->from_email ?: config('mail.from.address'), $s->from_name ?: $company];
    }
}
