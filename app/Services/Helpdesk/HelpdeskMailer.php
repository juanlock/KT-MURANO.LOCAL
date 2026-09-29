<?php

namespace App\Services\Helpdesk;

use App\Models\HelpdeskSetting;
use App\Models\HelpdeskTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Emails to the requester. Sent through the company's helpdesk SMTP when it
 * is set up (same login as its IMAP mailbox), else the install's mail server
 * (servers table). The subject carries "[HD-00012]" and Reply-To is the
 * helpdesk mailbox, so the requester's reply is imported into the SAME
 * ticket by HelpdeskMailFetcher.
 *
 * Never sends to reserved test domains (.test/.example/.invalid/.localhost,
 * example.com/.net/.org) -- logged instead. A failure never breaks the
 * request; it is logged and kept in helpdesk_settings.last_error.
 */
class HelpdeskMailer
{
    public static function send(HelpdeskTicket $ticket, string $subject, string $body, array $attachments = []): bool
    {
        $to = $ticket->requester_email;
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (preg_match('/(\.(test|example|invalid|localhost)|@example\.(com|net|org))$/i', $to)) {
            Log::info('helpdesk mail (reserved domain, not sent)', ['to' => $to, 'ticket' => $ticket->number, 'subject' => $subject]);

            return false;
        }

        $s = HelpdeskSetting::forCompany($ticket->company_id);
        $company = DB::table('companies')->where('id', $ticket->company_id)->value('name');
        $tagged = '['.$ticket->number.'] '.preg_replace('/^\s*(re:\s*)?\['.preg_quote($ticket->number, '/').'\]\s*/i', '', $subject);

        $link = in_array($ticket->source, ['public_form', 'email'], true) || $ticket->requester_type === 'guest' || $ticket->requester_type === 'supplier'
            ? "\n\nFollow your request: ".$ticket->publicUrl()
            : '';
        $text = $body.$link."\n\n--\n".($s->from_name ?: $company ?: 'Support')."\nTicket ".$ticket->number;
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222">'
            .nl2br(e($body))
            .($link ? '<p><a href="'.e($ticket->publicUrl()).'">Follow your request '.e($ticket->number).'</a></p>' : '')
            .'<p style="color:#888;font-size:12px;margin-top:24px">'.e($s->from_name ?: $company ?: 'Support').' · Ticket '.e($ticket->number).'<br>Reply to this email to answer.</p></div>';

        try {
            [$mailer, $from] = self::mailer($s);
            $replyTo = $s->address() ?: $from;
            $mailer->html($html, function ($m) use ($to, $ticket, $tagged, $from, $replyTo, $s, $company, $attachments, $text) {
                $m->to($to, $ticket->requester_name ?: null)
                    ->from($from, $s->from_name ?: $company ?: 'Support')
                    ->replyTo($replyTo)
                    ->subject($tagged);
                $m->getSymfonyMessage()->text($text);
                $m->getSymfonyMessage()->getHeaders()->addTextHeader('X-Helpdesk-Ticket', $ticket->number);
                foreach ($attachments as $a) {
                    if (! empty($a['path']) && Storage::disk('local')->exists($a['path'])) {
                        $m->attach(Storage::disk('local')->path($a['path']), ['as' => $a['name'] ?? basename($a['path'])]);
                    }
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('helpdesk mail failed: '.$e->getMessage(), ['ticket' => $ticket->number]);
            $s->forceFill(['last_error' => 'Sending: '.mb_substr($e->getMessage(), 0, 500)])->save();

            return false;
        }
    }

    /** [Mailer, from address] -- the company's SMTP, else the install's server. */
    private static function mailer(HelpdeskSetting $s): array
    {
        if ($s->smtp_host && $s->imap_username && $s->imap_password) {
            $port = (int) ($s->smtp_port ?: 587);

            return [Mail::build([
                'transport' => 'smtp',
                'host' => $s->smtp_host,
                'port' => $port,
                'scheme' => ($s->smtp_encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
                'username' => $s->imap_username,
                'password' => $s->imap_password,
                'timeout' => 20,
            ]), $s->address() ?: $s->imap_username];
        }

        $srv = DB::table('servers')->whereNull('deleted_at')->first();
        if ($srv && $srv->host) {
            $port = (int) ($srv->port ?: 587);

            return [Mail::build([
                'transport' => 'smtp',
                'host' => $srv->host,
                'port' => $port,
                'scheme' => ($srv->encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
                'username' => $srv->username,
                'password' => $srv->password,
                'timeout' => 20,
            ]), $srv->sender_email ?: config('mail.from.address')];
        }

        return [Mail::mailer(), config('mail.from.address')];
    }
}
