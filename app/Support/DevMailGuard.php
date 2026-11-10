<?php

namespace App\Support;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A developer PC never emails real people (user's rule, 2026-11-10).
 *
 * Every morning a developer PC copies production: the companies' own mail servers
 * and their real admins / customers come with it, so a register opened while
 * testing emailed Turismo's admins, the client included. On a developer PC every
 * email sent through Laravel (company SMTP, Helpdesk, Email Office, notifications)
 * goes to ONE address instead, with the real recipients in the subject.
 *
 * Developer PC = the database is `dbmurano` (production's has another name).
 * `.env` `MAIL_DEV_TO` overrides the address; `MAIL_DEV_TO=log` sends nothing
 * and only writes the email to the log.
 */
class DevMailGuard
{
    public const DEFAULT_TO = 'juanlock@kcoltech.com';

    /** Where this install's email goes instead of its recipients; null = production, send as is. */
    public static function target(): ?string
    {
        $override = trim((string) env('MAIL_DEV_TO', ''));
        try {
            $dev = DB::connection()->getDatabaseName() === 'dbmurano';
        } catch (\Throwable $e) {
            $dev = false;
        }
        if (! $dev) {
            return null;
        }

        return $override !== '' ? $override : self::DEFAULT_TO;
    }

    /** MessageSending listener: redirect (or stop) the email on a developer PC. */
    public static function handle(MessageSending $event)
    {
        $to = self::target();
        $message = $event->message;
        if ($to === null || ! $message instanceof Email) {
            return null;
        }

        $list = fn (array $a) => implode(', ', array_map(fn (Address $x) => $x->getAddress(), $a));
        $real = trim($list($message->getTo())
            .($message->getCc() ? ' cc: '.$list($message->getCc()) : '')
            .($message->getBcc() ? ' bcc: '.$list($message->getBcc()) : ''));
        $subject = (string) $message->getSubject();

        if (strtolower($to) === 'log' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Log::info('DevMailGuard: email not sent (developer PC)', ['to' => $real, 'subject' => $subject]);

            return false;
        }

        $message->to($to);
        foreach (['Cc', 'Bcc'] as $h) {
            $message->getHeaders()->remove($h);
        }
        $message->subject('[DEV -> '.mb_strimwidth($real, 0, 150, '...').'] '.$subject);
        $message->getHeaders()->addTextHeader('X-Murano-Original-To', mb_strimwidth($real, 0, 900, '...'));
        Log::info('DevMailGuard: email redirected (developer PC)', ['to' => $to, 'real' => $real, 'subject' => $subject]);

        return null;
    }
}
