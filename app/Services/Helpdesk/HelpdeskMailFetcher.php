<?php

namespace App\Services\Helpdesk;

use App\Models\HelpdeskMessage;
use App\Models\HelpdeskSetting;
use App\Models\HelpdeskTicket;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\ClientManager;

/**
 * Reads a company's helpdesk mailbox (IMAP, webklex/php-imap -- no PHP imap
 * extension needed) and imports UNSEEN emails:
 *  - subject with "[HD-00012]" of an existing ticket -> reply added to it
 *    (quoted history below "On ... wrote:" / "> " is cut off);
 *  - otherwise -> a new ticket, requester identified by the sender address
 *    (employee / customer / online customer / supplier / guest).
 * Each email is marked Seen and remembered by Message-ID, so nothing is
 * imported twice. Loops are avoided: mail from the helpdesk's own address
 * and automatic replies (Auto-Submitted, bounces) are skipped.
 *
 * Run by `php artisan helpdesk:fetch-mail` (scheduled every 5 minutes) or
 * "Check mailbox now" in Helpdesk settings.
 */
class HelpdeskMailFetcher
{
    /** @return array{imported:int, replies:int, skipped:int} */
    public static function fetch(HelpdeskSetting $s, int $limit = 50): array
    {
        $stats = ['imported' => 0, 'replies' => 0, 'skipped' => 0];
        $client = self::client($s);
        $client->connect();
        try {
            $folder = $client->getFolderByPath($s->imap_folder ?: 'INBOX');
            if (! $folder) {
                throw new \RuntimeException('Folder "'.($s->imap_folder ?: 'INBOX').'" not found in the mailbox.');
            }
            $messages = $folder->query()->unseen()->setFetchOrder('asc')->limit($limit)->get();
            $own = strtolower((string) $s->address());

            foreach ($messages as $mail) {
                try {
                    $r = self::importOne($s, $mail, $own);
                    $stats[$r]++;
                } catch (\Throwable $e) {
                    report($e);
                    $stats['skipped']++;
                }
                try {
                    $mail->setFlag('Seen');
                } catch (\Throwable $e) { /* read-only mailbox */ }
            }
            $s->forceFill(['last_polled_at' => now(), 'last_error' => null])->save();
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }

        return $stats;
    }

    /** Just logs in and opens the folder (settings "Test connection"). */
    public static function test(HelpdeskSetting $s): array
    {
        $client = self::client($s);
        $client->connect();
        $folder = $client->getFolderByPath($s->imap_folder ?: 'INBOX');
        $unseen = $folder ? $folder->query()->unseen()->count() : null;
        $client->disconnect();
        if (! $folder) {
            throw new \RuntimeException('Connected, but folder "'.($s->imap_folder ?: 'INBOX').'" was not found.');
        }

        return ['unseen' => $unseen];
    }

    private static function client(HelpdeskSetting $s)
    {
        if (! $s->imap_host || ! $s->imap_username || ! $s->imap_password) {
            throw new \RuntimeException('The mailbox is not fully set up (host, username and password are required).');
        }
        $enc = $s->imap_encryption ?: 'ssl';

        return (new ClientManager())->make([
            'host' => $s->imap_host,
            'port' => (int) ($s->imap_port ?: ($enc === 'ssl' ? 993 : 143)),
            'encryption' => $enc === 'none' ? false : $enc,
            'validate_cert' => true,
            'username' => $s->imap_username,
            'password' => $s->imap_password,
            'protocol' => 'imap',
            'timeout' => 30,
        ]);
    }

    /** @return string imported|replies|skipped */
    private static function importOne(HelpdeskSetting $s, $mail, string $own): string
    {
        $companyId = (int) $s->company_id;
        $mid = trim((string) $mail->getMessageId()) ?: null;
        if ($mid && HelpdeskMessage::where('company_id', $companyId)->where('email_message_id', $mid)->exists()) {
            return 'skipped';
        }
        $from = $mail->getFrom()[0] ?? null;
        $email = strtolower(trim((string) ($from->mail ?? '')));
        $name = trim((string) ($from->personal ?? '')) ?: null;
        if ($email === '' || $email === $own) {
            return 'skipped';
        }
        $auto = strtolower((string) ($mail->getHeader()->get('auto-submitted') ?? ''));
        if (($auto !== '' && $auto !== 'no') || preg_match('/^(mailer-daemon|postmaster|no-?reply)@/i', $email)) {
            return 'skipped';
        }

        $subject = trim((string) $mail->getSubject()) ?: '(no subject)';
        $body = $mail->hasTextBody() ? (string) $mail->getTextBody() : self::htmlToText((string) $mail->getHTMLBody());
        $attachments = [];
        foreach ($mail->getAttachments() as $a) {
            $bytes = (string) $a->getContent();
            if ($bytes === '' || strlen($bytes) > Helpdesk::ATTACH_MAX_KB * 1024) {
                continue;
            }
            $attachments[] = Helpdesk::storeBytes($companyId, (string) $a->getName(), $bytes, $a->getMimeType());
        }

        // A reply to an existing ticket?
        if (preg_match_all('/\[([A-Za-z0-9-]*\d+)\]/', $subject, $m)) {
            foreach ($m[1] as $number) {
                $t = HelpdeskTicket::where('company_id', $companyId)->where('number', $number)->first();
                if ($t) {
                    $isAgent = HelpdeskAccess::can(\App\Models\User::where('company_id', $companyId)->whereRaw('LOWER(email) = ?', [$email])->first(), 'helpdesk_view')
                        && strtolower((string) $t->requester_email) !== $email;
                    Helpdesk::addMessage($t, $isAgent ? 'agent' : 'requester', self::stripQuoted($body) ?: '(empty message)', [
                        'name' => $name, 'email' => $email, 'via' => 'email', 'email_message_id' => $mid,
                        'attachments' => $attachments, 'notify' => $isAgent,
                    ]);

                    return 'replies';
                }
            }
        }

        Helpdesk::open($companyId, $subject, self::stripQuoted($body) ?: '(empty message)', ['name' => $name, 'email' => $email], 'email', [
            'email_message_id' => $mid, 'attachments' => $attachments,
        ]);

        return 'imported';
    }

    /** Cuts the quoted history of a reply ("On ... wrote:", "> ", "-----Original Message-----"). */
    public static function stripQuoted(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $lines = explode("\n", $body);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(On .+wrote:|El .+escribió:|Le .+a écrit\s*:|-{2,}\s*Original Message\s*-{2,}|From:\s.+|De:\s.+|_{8,})\s*$/iu', $line)
                || str_contains($line, 'Reply to this email to answer.')) {
                break;
            }
            if (preg_match('/^\s*>/', $line)) {
                continue;
            }
            $out[] = $line;
        }

        return trim(Str::limit(trim(implode("\n", $out)), 60000, ''));
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('~<(script|style)\b.*?</\1>~is', '', $html);
        $html = preg_replace('~<br\s*/?>|</(p|div|li|tr|h[1-6])>~i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
