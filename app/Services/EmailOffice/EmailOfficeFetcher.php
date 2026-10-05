<?php

namespace App\Services\EmailOffice;

use App\Models\EmailOfficeSetting;
use Webklex\PHPIMAP\ClientManager;

/**
 * Reads a company's mailbox (Email Office -> Settings) into office_emails:
 * the Inbox (received) and, when "Also import sent mail" is on, the Sent
 * folder (sent from webmail / a phone). Never deletes or moves mail and
 * leaves read/unread as it was. Duplicates are stopped by Message-ID
 * (EmailOffice::record), so each run simply re-reads the recent days.
 *
 * IMAP by default (webklex/php-imap, no PHP extension needed). POP3 has no
 * folders (Inbox only) and needs the PHP "imap" extension.
 *
 * Run by `php artisan email-office:fetch` (every minute; each company at its
 * own "check every" interval) or "Check mailbox now".
 */
class EmailOfficeFetcher
{
    /** @return array{received:int, sent:int, skipped:int} */
    public static function fetch(EmailOfficeSetting $s, int $limit = 200): array
    {
        $stats = ['received' => 0, 'sent' => 0, 'skipped' => 0];
        $client = self::client($s);
        try {
            $client->connect();
            // First run: the last "days back"; then re-read 2 days before the last check.
            $since = $s->last_polled_at ? $s->last_polled_at->copy()->subDays(2) : now()->subDays(max(1, (int) $s->days_back ?: 30));
            $own = (string) $s->address();

            $inbox = $client->getFolderByPath($s->folder ?: 'INBOX');
            if (! $inbox) {
                throw new \RuntimeException('Folder "'.($s->folder ?: 'INBOX').'" not found in the mailbox.');
            }
            foreach (self::messages($inbox, $since, $limit, $s->protocol) as $mail) {
                $stats[self::importLazy($s, $mail, 'in') ? 'received' : 'skipped']++;
            }
            if ($s->import_sent && $s->protocol !== 'pop3' && ($sent = self::sentFolder($client))) {
                foreach (self::messages($sent, $since, $limit, $s->protocol) as $mail) {
                    $stats[self::importLazy($s, $mail, 'out') ? 'sent' : 'skipped']++;
                }
            }
            $s->forceFill(['last_polled_at' => now(), 'last_error' => null, 'last_stats' => $stats])->save();
        } catch (\Throwable $e) {
            $s->forceFill(['last_polled_at' => now(), 'last_error' => mb_substr(self::explain($e, $s), 0, 1000)])->save();
            throw $e;
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }

        return $stats;
    }

    /* ------------------------------------------------ import ALL (first time) */

    /**
     * Starts "Import all emails": counts the folders to read (Inbox, plus
     * Sent when "also import sent" is on). The batches then run from the
     * Settings page (fullStep, while it's open) and from email-office:fetch
     * (scheduler), oldest first. Emails already imported are skipped, so it
     * can be paused, resumed or run again safely.
     */
    public static function fullStart(EmailOfficeSetting $s): array
    {
        $client = self::client($s);
        $client->connect();
        try {
            $folders = [];
            $inbox = $client->getFolderByPath($s->folder ?: 'INBOX');
            if (! $inbox) {
                throw new \RuntimeException('Folder "'.($s->folder ?: 'INBOX').'" not found in the mailbox.');
            }
            $folders[] = ['path' => $inbox->path, 'direction' => 'in', 'total' => $inbox->query()->all()->count(), 'page' => 0, 'done' => false];
            if ($s->import_sent && $s->protocol !== 'pop3' && ($sent = self::sentFolder($client))) {
                $folders[] = ['path' => $sent->path, 'direction' => 'out', 'total' => $sent->query()->all()->count(), 'page' => 0, 'done' => false];
            }
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }
        $state = [
            'status' => 'running', 'folders' => $folders, 'batch' => 50,
            'imported' => 0, 'skipped' => 0, 'read' => 0, 'total' => array_sum(array_column($folders, 'total')),
            'started_at' => now()->toDateTimeString(), 'finished_at' => null, 'error' => null,
        ];
        $s->forceFill(['full_import' => $state])->save();

        return $state;
    }

    /**
     * One or more batches of the running full import (oldest emails first,
     * $batch per page). Stops early after ~$seconds. Returns the state.
     */
    public static function fullStep(EmailOfficeSetting $s, int $maxBatches = 1, int $seconds = 40): array
    {
        $state = $s->full_import ?: [];
        if (($state['status'] ?? null) !== 'running') {
            return $state;
        }
        @set_time_limit(max(60, $seconds + 30));
        $started = microtime(true);
        $client = self::client($s);
        try {
            $client->connect();
            for ($b = 0; $b < $maxBatches && microtime(true) - $started < $seconds; $b++) {
                $i = collect($state['folders'])->search(fn ($f) => ! $f['done']);
                if ($i === false) {
                    $state['status'] = 'done';
                    $state['finished_at'] = now()->toDateTimeString();
                    break;
                }
                $f = &$state['folders'][$i];
                $folder = $client->getFolderByPath($f['path']);
                if (! $folder) {
                    $f['done'] = true;
                    unset($f);
                    continue;
                }
                $page = $f['page'] + 1;
                try {
                    // Headers only: each new email's content is fetched on its own (importLazy).
                    $messages = $folder->query()->all()->setFetchOrder('asc')->leaveUnread()->setFetchBody(false)->limit($state['batch'], $page)->get();
                    $count = $messages->count();
                    foreach ($messages as $mail) {
                        try {
                            $state[self::importLazy($s, $mail, $f['direction']) ? 'imported' : 'skipped']++;
                        } catch (\Throwable $bad) {
                            if (self::isConnectionError($bad)) {
                                throw $bad;
                            }
                            $state['skipped']++;
                            $state['unreadable'] = ($state['unreadable'] ?? 0) + 1;
                        }
                        $state['read']++;
                    }
                    unset($messages, $mail);
                } catch (\Throwable $pageError) {
                    if (self::isConnectionError($pageError)) {
                        throw $pageError; // retry the same page next step
                    }
                    // One bad email (broken headers) made the whole page fail:
                    // read this page one email at a time and skip only the bad ones.
                    $count = 0;
                    for ($k = 1; $k <= $state['batch']; $k++) {
                        $n = ($page - 1) * $state['batch'] + $k;
                        if ($n > $f['total']) {
                            break;
                        }
                        $count++;
                        $state['read']++;
                        try {
                            $one = $folder->query()->all()->setFetchOrder('asc')->leaveUnread()->setFetchBody(false)->limit(1, $n)->get()->first();
                            $state[$one && self::importLazy($s, $one, $f['direction']) ? 'imported' : 'skipped']++;
                        } catch (\Throwable $bad) {
                            $state['skipped']++;
                            $state['unreadable'] = ($state['unreadable'] ?? 0) + 1;
                        }
                    }
                }
                $f['page'] = $page;
                if ($count < $state['batch'] || $page * $state['batch'] >= $f['total']) {
                    $f['done'] = true;
                }
                unset($f);
            }
            if (collect($state['folders'])->every(fn ($f) => $f['done']) && $state['status'] === 'running') {
                $state['status'] = 'done';
                $state['finished_at'] = now()->toDateTimeString();
            }
            $state['error'] = null;
        } catch (\Throwable $e) {
            // Keeps running: the next step retries this same page.
            $state['error'] = self::explain($e, $s);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }
        // Don't overwrite a pause / cancel made while this batch ran.
        $now = $s->fresh()->full_import ?: [];
        if (($now['status'] ?? null) !== 'running' && ($state['status'] ?? null) === 'running') {
            $state['status'] = $now['status'];
        }
        $s->forceFill(['full_import' => $state] + ($state['status'] === 'done' ? ['last_polled_at' => now()] : []))->save();

        return $state;
    }

    /** Network / login problem (retry later) vs a problem with one email's content. */
    private static function isConnectionError(\Throwable $e): bool
    {
        for ($x = $e; $x; $x = $x->getPrevious()) {
            if ($x instanceof \Webklex\PHPIMAP\Exceptions\ConnectionFailedException
                || $x instanceof \Webklex\PHPIMAP\Exceptions\AuthFailedException
                || preg_match('/getaddrinfo|refused|timed out|timeout|broken pipe|connection (closed|reset|lost)|AUTHENTICATIONFAILED/i', $x->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /** Logs in and opens the folder (Settings -> Test connection). */
    public static function test(EmailOfficeSetting $s): array
    {
        $client = self::client($s);
        $client->connect();
        try {
            $folder = $client->getFolderByPath($s->folder ?: 'INBOX');
            if (! $folder) {
                throw new \RuntimeException('Connected, but folder "'.($s->folder ?: 'INBOX').'" was not found.');
            }
            $folders = [];
            if ($s->protocol !== 'pop3') {
                foreach ($client->getFolders(false) as $f) {
                    $folders[] = $f->path;
                }
            }

            return [
                'messages' => $folder->query()->all()->count(),
                'folders' => $folders,
                'sent_folder' => $s->protocol !== 'pop3' ? optional(self::sentFolder($client))->path : null,
            ];
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * A connection error in plain words. The IMAP library only says
     * "connection failed"; the real cause (unknown server, refused port,
     * wrong password, certificate) is in the exception chain. For an unknown
     * server it also suggests the right one from the email domain's MX
     * records (where that domain's mail is really hosted).
     */
    public static function explain(\Throwable $e, EmailOfficeSetting $s): string
    {
        $chain = [];
        for ($x = $e; $x; $x = $x->getPrevious()) {
            $chain[] = $x->getMessage();
        }
        $raw = implode(' | ', $chain);
        $host = (string) $s->host;
        $port = (int) ($s->port ?: 993);

        if (preg_match('/getaddrinfo|No such host|Name or service not known|nodename nor servname/i', $raw)) {
            $hint = self::suggestServer((string) $s->username);

            return "The server name “{$host}” doesn't exist."
                .($hint ? " For {$hint['domain']} the email is hosted by {$hint['provider']}: use server {$hint['imap']} (port 993, SSL)".($hint['smtp'] ? " and for sending {$hint['smtp']}" : '').'.' : ' Check the server name your email provider gives for IMAP.');
        }
        if (preg_match('/refused|timed out|timeout|Network is unreachable|failed to connect/i', $raw)) {
            return "Could not reach {$host} on port {$port}. Check the port and security (IMAP: 993 with SSL, or 143 with TLS) and that the provider allows IMAP.";
        }
        // The account doesn't live on this server (e.g. Titan's numbered
        // servers like imap0101.titan.email): the password may be right.
        if (preg_match('/AccountNotFound|no such user|user unknown|mailbox not found/i', $raw)) {
            $hint = self::suggestServer((string) $s->username);

            return "The server {$host} doesn't have the account {$s->username} (the password may be fine)."
                .($hint ? " Use the general server for {$hint['provider']}: {$hint['imap']}." : ' Use the general IMAP server your provider gives, not a numbered one.');
        }
        if (preg_match('/AUTHENTICATIONFAILED|LOGIN failed|authentication failed|Invalid credentials|incorrect|username and password not accepted|AUTHENTICATE failed/i', $raw)) {
            // Providers give the same answer for "wrong password" and "account
            // not on this server": when the server isn't the provider's general
            // one, say so first.
            $hint = self::suggestServer((string) $s->username);
            if ($hint && strtolower($host) !== $hint['imap']) {
                return "The server {$host} rejected the login. For {$hint['domain']} use the general {$hint['provider']} server {$hint['imap']} (a numbered server like {$host} may not have this account); if it still fails, check the password.";
            }

            return 'The server rejected the email or password. Check them; with Gmail / Outlook / Yahoo and 2-step login you need an App password, not your normal password.';
        }
        if (preg_match('/certificate|SSL operation failed|peer/i', $raw)) {
            return "The security certificate of {$host} doesn't match that name. Use the server name your provider gives (not your own domain).";
        }

        return 'Connection failed: '.($chain[count($chain) - 1] ?? $e->getMessage());
    }

    /**
     * The provider's general servers when the saved IMAP server isn't them
     * (Settings shows a "Use these servers" button), else null.
     */
    public static function suggestion(EmailOfficeSetting $s): ?array
    {
        $hint = self::suggestServer((string) $s->username);
        if (! $hint || strtolower(trim((string) $s->host)) === $hint['imap']) {
            return null;
        }
        $ssl = $hint['imap'] !== 'outlook.office365.com';

        return [
            'provider' => $hint['provider'],
            'host' => $hint['imap'], 'port' => 993, 'encryption' => 'ssl',
            'smtp_host' => $hint['smtp'], 'smtp_port' => $ssl ? 465 : 587, 'smtp_encryption' => $ssl ? 'ssl' : 'tls',
        ];
    }

    /** {domain, provider, imap, smtp} from the email domain's MX records, or null. */
    public static function suggestServer(string $email): ?array
    {
        $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));
        if ($domain === '' || ! function_exists('dns_get_record')) {
            return null;
        }
        $mx = strtolower(implode(' ', array_column(@dns_get_record($domain, DNS_MX) ?: [], 'target')));
        $known = [
            'titan.email' => ['Titan (Hostinger email)', 'imap.titan.email', 'smtp.titan.email'],
            'google.com' => ['Google Workspace / Gmail', 'imap.gmail.com', 'smtp.gmail.com'],
            'googlemail.com' => ['Google Workspace / Gmail', 'imap.gmail.com', 'smtp.gmail.com'],
            'outlook.com' => ['Microsoft 365 / Outlook', 'outlook.office365.com', 'smtp.office365.com'],
            'hostinger.com' => ['Hostinger', 'imap.hostinger.com', 'smtp.hostinger.com'],
            'secureserver.net' => ['GoDaddy', 'imap.secureserver.net', 'smtpout.secureserver.net'],
            'zoho.com' => ['Zoho', 'imap.zoho.com', 'smtp.zoho.com'],
            'yahoodns.net' => ['Yahoo', 'imap.mail.yahoo.com', 'smtp.mail.yahoo.com'],
        ];
        foreach ($known as $needle => [$provider, $imap, $smtp]) {
            if ($mx !== '' && str_contains($mx, $needle)) {
                return ['domain' => $domain, 'provider' => $provider, 'imap' => $imap, 'smtp' => $smtp];
            }
        }

        return null;
    }

    /** Bigger than this: saved with its headers only (memory), with a note to open it in webmail. */
    public const MAX_EMAIL_BYTES = 30 * 1024 * 1024;

    /**
     * Imports a message fetched WITHOUT its body: already imported -> skipped
     * without downloading anything; too big -> headers only; else its content
     * is downloaded now (one email in memory at a time) and imported.
     */
    private static function importLazy(EmailOfficeSetting $s, $mail, string $direction): bool
    {
        if (EmailOffice::alreadyHas((int) $s->company_id, (string) $mail->getMessageId())) {
            return false;
        }
        $size = 0;
        try {
            $size = (int) $mail->getSize();
        } catch (\Throwable $e) {
        }
        if ($size > self::MAX_EMAIL_BYTES) {
            $from = $mail->getFrom()[0] ?? null;
            $date = $mail->getDate();
            $date = $date ? $date->toDate() : now();

            return (bool) EmailOffice::record((int) $s->company_id, [
                'direction' => $direction, 'source' => 'mailbox', 'message_id' => (string) $mail->getMessageId(),
                'subject' => (string) $mail->getSubject(), 'from_email' => (string) ($from->mail ?? ''), 'from_name' => (string) ($from->personal ?? ''),
                'to' => collect($mail->getTo() ? $mail->getTo()->all() : [])->map(fn ($a) => (string) ($a->mail ?? ''))->all(),
                'sent_at' => $date->format('Y') < 1990 ? now() : $date,
                'body_text' => '(This email is '.round($size / 1048576).' MB -- too large to copy here. Open it in webmail.)',
            ]);
        }
        $mail->parseBody();
        $ok = self::importOne($s, $mail, $direction);
        gc_collect_cycles();

        return $ok;
    }

    private static function messages($folder, $since, int $limit, string $protocol)
    {
        $q = $folder->query()->setFetchOrder('desc')->limit($limit)->leaveUnread()->setFetchBody(false);

        return $protocol === 'pop3' ? $q->all()->get() : $q->since($since)->get();
    }

    private static function importOne(EmailOfficeSetting $s, $mail, string $direction): bool
    {
        try {
            // Duplicate: stop before saving its attachments (else orphan files).
            if (EmailOffice::alreadyHas((int) $s->company_id, (string) $mail->getMessageId())) {
                return false;
            }
            $from = $mail->getFrom()[0] ?? null;
            $addr = fn ($list) => collect($list ? $list->all() : [])->map(fn ($a) => (string) ($a->mail ?? ''))->all();
            $attachments = [];
            foreach ($mail->getAttachments() as $a) {
                $d = EmailOffice::storeAttachment((int) $s->company_id, (string) $a->getName(), (string) $a->getContent(), $a->getMimeType());
                if ($d) {
                    $attachments[] = $d;
                }
            }
            $date = $mail->getDate();
            $date = $date ? $date->toDate() : now();
            if ($date->format('Y') < 1990) {
                $date = now(); // the fallback for an unreadable Date header
            }

            return (bool) EmailOffice::record((int) $s->company_id, [
                'direction' => $direction,
                'source' => 'mailbox',
                'message_id' => (string) $mail->getMessageId(),
                'in_reply_to' => (string) ($mail->getInReplyTo() ?? ''),
                'subject' => (string) $mail->getSubject(),
                'from_email' => (string) ($from->mail ?? ''),
                'from_name' => (string) ($from->personal ?? ''),
                'to' => $addr($mail->getTo()),
                'cc' => $addr($mail->getCc()),
                'sent_at' => $date,
                'body_text' => $mail->hasTextBody() ? (string) $mail->getTextBody() : '',
                'body_html' => $mail->hasHTMLBody() ? (string) $mail->getHTMLBody() : null,
                'attachments' => $attachments,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private static function sentFolder($client)
    {
        foreach (['Sent', 'Sent Items', 'Sent Messages', 'Sent Mail', 'INBOX.Sent', 'INBOX/Sent', '[Gmail]/Sent Mail', 'Enviados', 'Elementos enviados'] as $path) {
            try {
                if ($f = $client->getFolderByPath($path)) {
                    return $f;
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private static function client(EmailOfficeSetting $s)
    {
        if (! $s->host || ! $s->username || ! $s->password) {
            throw new \RuntimeException('The mailbox is not fully set up (server, username and password are required).');
        }
        if ($s->protocol === 'pop3' && ! function_exists('imap_open')) {
            throw new \RuntimeException('POP3 needs the PHP "imap" extension, which this server does not have. Use IMAP (recommended) -- almost every provider offers it.');
        }
        $enc = $s->encryption ?: 'ssl';
        $defaultPort = $s->protocol === 'pop3' ? ($enc === 'ssl' ? 995 : 110) : ($enc === 'ssl' ? 993 : 143);

        // fallback_date: an email with a broken Date header still imports (dated now) instead of failing its whole batch.
        return (new ClientManager(['options' => ['fallback_date' => '1970-01-01 00:00:00']]))->make([
            'host' => $s->host,
            'port' => (int) ($s->port ?: $defaultPort),
            'encryption' => $enc === 'none' ? false : $enc,
            'validate_cert' => true,
            'username' => $s->username,
            'password' => $s->password,
            'protocol' => $s->protocol === 'pop3' ? 'pop3' : 'imap',
            'timeout' => 30,
        ]);
    }
}
