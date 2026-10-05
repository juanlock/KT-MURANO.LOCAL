<?php

namespace App\Services\Helpdesk;

use App\Models\HelpdeskMessage;
use App\Models\HelpdeskSetting;
use App\Models\HelpdeskTicket;
use App\Models\User;
use App\Support\CompanyFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Helpdesk core (per company): opening tickets, adding messages, working
 * out WHO the requester is, attachments, and the emails that go out.
 *
 * Requesters: employee (staff user, in the app), customer (client portal /
 * customer record), online_customer (store account), supplier (providers,
 * matched by email) and guest (anyone else -- public form or email). Guests
 * and email requesters follow their ticket through a private link
 * (/support/t/{token}) and by replying to the emails.
 *
 * Message bodies are PLAIN TEXT (escaped on display), never HTML.
 */
class Helpdesk
{
    public const ATTACH_MAX_KB = 10240;
    public const ATTACH_MIMES = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip';

    /** Who owns this address in the company: [type, user_id, client_id, provider_id, name]. */
    public static function identify(int $companyId, ?string $email): array
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return ['guest', null, null, null, null];
        }
        $u = User::where('company_id', $companyId)->whereRaw('LOWER(email) = ?', [$email])->whereNull('deleted_at')->first();
        if ($u && $u->type !== 'customer') {
            return ['employee', $u->id, null, null, trim($u->firstname.' '.$u->lastname) ?: $u->username];
        }
        $client = DB::table('clients')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(function ($q) use ($email, $u) {
                $q->whereRaw('LOWER(email) = ?', [$email]);
                if ($u) {
                    $q->orWhere('user_id', $u->id);
                }
            })->first();
        if ($client) {
            $online = DB::table('ecommerce_clients')->where('client_id', $client->id)->whereNull('deleted_at')->exists();

            return [$online ? 'online_customer' : 'customer', $u?->id, $client->id, null, $client->name];
        }
        $provider = DB::table('providers')->where('company_id', $companyId)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($provider) {
            return ['supplier', null, null, $provider->id, $provider->name];
        }

        return ['guest', null, null, null, null];
    }

    /**
     * Opens a ticket with its first message.
     * $r = [type?, user_id?, client_id?, provider_id?, name, email]; $source = app|portal|store|public_form|email|admin
     */
    public static function open(int $companyId, string $subject, string $body, array $r, string $source, array $opts = []): HelpdeskTicket
    {
        $s = HelpdeskSetting::forCompany($companyId);
        if (empty($r['type'])) {
            [$r['type'], $uid, $cid, $pid, $name] = self::identify($companyId, $r['email'] ?? null);
            $r['user_id'] ??= $uid;
            $r['client_id'] ??= $cid;
            $r['provider_id'] ??= $pid;
            $r['name'] = ($r['name'] ?? '') ?: $name;
        }

        $ticket = DB::transaction(function () use ($companyId, $s, $subject, $body, $r, $source, $opts) {
            // Numbering per company: lock the settings row while picking the next number.
            HelpdeskSetting::whereKey($s->id)->lockForUpdate()->first();
            $last = HelpdeskTicket::where('company_id', $companyId)->orderByDesc('id')->value('number');
            $n = $last && preg_match('/(\d+)$/', $last, $m) ? ((int) $m[1] + 1) : 1;

            $ticket = HelpdeskTicket::create([
                'company_id' => $companyId,
                'number' => ($s->ticket_prefix ?: 'HD-').str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'subject' => Str::limit(trim($subject) ?: '(no subject)', 250, ''),
                'status' => 'open',
                'priority' => in_array($opts['priority'] ?? '', HelpdeskTicket::PRIORITIES, true) ? $opts['priority'] : 'normal',
                'category' => isset($opts['category']) ? Str::limit((string) $opts['category'], 80, '') : null,
                'source' => $source,
                'requester_type' => in_array($r['type'], HelpdeskTicket::REQUESTERS, true) ? $r['type'] : 'guest',
                'requester_user_id' => $r['user_id'] ?? null,
                'client_id' => $r['client_id'] ?? null,
                'provider_id' => $r['provider_id'] ?? null,
                'requester_name' => Str::limit((string) ($r['name'] ?? ''), 190, '') ?: null,
                'requester_email' => isset($r['email']) ? Str::limit(strtolower(trim($r['email'])), 190, '') : null,
                'assigned_to' => $opts['assigned_to'] ?? null,
                'public_token' => Str::random(48),
                'last_message_at' => now(),
            ]);
            // first_author 'agent': a conversation the helpdesk started (an
            // email written from its mailbox in webmail) -- the first message
            // is the agent's, to the requester.
            $agentFirst = ($opts['first_author'] ?? 'requester') === 'agent';
            self::addMessage($ticket, $agentFirst ? 'agent' : 'requester', $body, [
                'user_id' => $agentFirst ? null : ($r['user_id'] ?? null),
                'name' => $agentFirst ? ($opts['author_name'] ?? null) : $ticket->requester_name,
                'email' => $agentFirst ? ($opts['author_email'] ?? null) : $ticket->requester_email,
                'via' => $source === 'admin' ? 'app' : $source, 'email_message_id' => $opts['email_message_id'] ?? null,
                'attachments' => $opts['attachments'] ?? [], 'notify' => false,
            ]);

            return $ticket;
        });

        // Acknowledgement to the requester (not for tickets agents open for themselves).
        if ($s->auto_reply && $ticket->requester_email && $source !== 'admin' && empty($opts['no_auto_reply'])) {
            HelpdeskMailer::send($ticket, 'Your request '.$ticket->number.' has been received',
                "Hello ".($ticket->requester_name ?: '').",\n\nThanks for contacting us. We received your request and will answer soon.\n\nYou can reply to this email to add information.");
        }

        return $ticket;
    }

    /**
     * Adds a message. $o: user_id, name, email, via, is_internal, email_message_id, attachments, notify (default true).
     * An agent's public reply emails the requester and sets the ticket to "pending";
     * a requester's message re-opens a pending/resolved ticket.
     */
    public static function addMessage(HelpdeskTicket $ticket, string $authorType, string $body, array $o = []): HelpdeskMessage
    {
        $msg = HelpdeskMessage::create([
            'company_id' => $ticket->company_id,
            'ticket_id' => $ticket->id,
            'author_type' => $authorType,
            'user_id' => $o['user_id'] ?? null,
            'author_name' => isset($o['name']) ? Str::limit((string) $o['name'], 190, '') : null,
            'author_email' => isset($o['email']) ? Str::limit((string) $o['email'], 190, '') : null,
            'body' => trim(str_replace("\r\n", "\n", $body)),
            'is_internal' => (bool) ($o['is_internal'] ?? false),
            'via' => $o['via'] ?? 'app',
            'email_message_id' => $o['email_message_id'] ?? null,
            'attachments' => $o['attachments'] ?? [],
        ]);

        $upd = ['last_message_at' => now()];
        if ($authorType === 'agent' && ! $msg->is_internal) {
            if (! $ticket->first_response_at) {
                $upd['first_response_at'] = now();
            }
            if ($ticket->status === 'open') {
                $upd['status'] = 'pending';
            }
        } elseif ($authorType === 'requester' && in_array($ticket->status, ['pending', 'resolved'], true)) {
            $upd['status'] = 'open';
            $upd['resolved_at'] = null;
        }
        $ticket->forceFill($upd)->save();

        if (($o['notify'] ?? true) && $authorType === 'agent' && ! $msg->is_internal && $ticket->requester_email) {
            HelpdeskMailer::send($ticket, 'Re: '.$ticket->subject, $msg->body, $msg->attachments ?? []);
        }

        return $msg;
    }

    public static function setStatus(HelpdeskTicket $ticket, string $status): void
    {
        abort_unless(in_array($status, HelpdeskTicket::STATUSES, true), 422);
        $ticket->forceFill([
            'status' => $status,
            'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? ($ticket->resolved_at ?? now()) : null,
        ])->save();
    }

    /**
     * Ticket attachments live in public/files/company_{id}/helpdesk/{Y}/{m}/
     * (project standard, App\Support\CompanyFiles), private: no direct URL,
     * served by download() after the ticket's access checks.
     */
    public const FILES_MODULE = 'helpdesk';

    /** Stores uploads for a ticket; returns the attachments list. */
    public static function storeUploads(int $companyId, array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            if (! $f instanceof UploadedFile || ! $f->isValid()) {
                continue;
            }
            $name = CompanyFiles::safeName($f->getClientOriginalName());
            $size = $f->getSize();
            $mime = $f->getMimeType();
            $path = CompanyFiles::putUpload($companyId, self::FILES_MODULE, $f, true);
            $out[] = ['name' => $name, 'path' => $path, 'size' => $size, 'mime' => $mime];
        }

        return $out;
    }

    /** Stores raw bytes (email attachments). */
    public static function storeBytes(int $companyId, string $name, string $bytes, ?string $mime): array
    {
        $name = CompanyFiles::safeName($name ?: 'attachment');
        $path = CompanyFiles::putBytes($companyId, self::FILES_MODULE, $name, $bytes, true);

        return ['name' => $name, 'path' => $path, 'size' => strlen($bytes), 'mime' => $mime];
    }

    /** Streams attachment #$index of a message, only when it belongs to the ticket's company folder. */
    public static function download(HelpdeskMessage $msg, int $index)
    {
        $a = ($msg->attachments ?? [])[$index] ?? null;
        abort_unless($a && CompanyFiles::owns($a['path'] ?? null, (int) $msg->company_id, self::FILES_MODULE), 404);

        return CompanyFiles::download($a['path'], $a['name']);
    }

    /** The ticket + its thread, for display. Internal notes only for agents. */
    public static function present(HelpdeskTicket $t, bool $forAgent): array
    {
        $msgs = $t->messages()->when(! $forAgent, fn ($q) => $q->where('is_internal', false))->get();

        return [
            'ticket' => $t->makeVisible($forAgent ? [] : [])->toArray() + [
                'assignee_name' => $t->assignee ? trim($t->assignee->firstname.' '.$t->assignee->lastname) : null,
            ],
            'messages' => $msgs->map(fn ($m) => [
                'id' => $m->id, 'author_type' => $m->author_type, 'author_name' => $m->author_name ?: ($m->author_type === 'agent' ? 'Support' : $m->author_email),
                'body' => $m->body, 'is_internal' => $m->is_internal, 'via' => $m->via, 'created_at' => $m->created_at?->toDateTimeString(),
                'attachments' => collect($m->attachments ?? [])->map(fn ($a, $i) => ['index' => $i, 'name' => $a['name'], 'size' => $a['size'] ?? null])->values(),
            ])->values(),
        ];
    }

    /** Staff users of the company who can work tickets (agents). */
    public static function agents(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('type', '!=', 'customer')->whereNull('deleted_at')->get()
            ->filter(fn ($u) => HelpdeskAccess::can($u, 'helpdesk_view'))
            ->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->firstname.' '.$u->lastname) ?: $u->username])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }
}
