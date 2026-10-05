<?php

namespace App\Listeners;

use App\Models\EmailOfficeSetting;
use App\Services\EmailOffice\EmailOffice;
use App\Support\ModuleFlags;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office -> Email sent: logs every email the system sends (invoices,
 * reminders, quotations, helpdesk replies, notifications...) in
 * office_emails, so each company sees what went out and how many reminders
 * were sent. Never breaks the sending: any problem here is only reported.
 *
 * Company: the X-Email-Office-Company header (set by Email Office's own
 * "New email"), else the logged-in user's acting company, else the company
 * whose mailbox / mail server sends from that address.
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        try {
            if (! Schema::hasTable('office_emails')) {
                return;
            }
            $msg = $event->message; // Symfony\Component\Mime\Email
            $headers = $msg->getHeaders();
            $companyId = (int) ($headers->get('X-Email-Office-Company')?->getBodyAsString() ?? 0) ?: $this->companyFromContext();
            $from = $msg->getFrom()[0] ?? null;
            $fromEmail = $from ? strtolower($from->getAddress()) : null;
            $companyId = $companyId ?: $this->companyFromAddress($fromEmail);
            if ($companyId && ! ModuleFlags::enabled($companyId, 'email_office')) {
                return;
            }
            $source = $headers->get('X-Email-Office-Source')?->getBodyAsString() === 'compose' ? 'compose' : 'system';

            $attachments = [];
            foreach ($msg->getAttachments() as $part) {
                // Names and sizes only -- the files are the system's own documents.
                $attachments[] = ['name' => $part->getFilename() ?: 'attachment', 'size' => strlen((string) $part->getBody()), 'mime' => $part->getMediaType().'/'.$part->getMediaSubtype()];
            }

            EmailOffice::record($companyId ?: null, [
                'direction' => 'out',
                'source' => $source,
                'message_id' => $event->sent->getMessageId(),
                'subject' => (string) $msg->getSubject(),
                'from_email' => $fromEmail,
                'from_name' => $from ? $from->getName() : null,
                'to' => array_map(fn ($a) => $a->getAddress(), $msg->getTo()),
                'cc' => array_map(fn ($a) => $a->getAddress(), $msg->getCc()),
                'sent_at' => now(),
                'body_text' => (string) ($msg->getTextBody() ?? ''),
                'body_html' => is_string($msg->getHtmlBody()) ? $msg->getHtmlBody() : null,
                'attachments' => $attachments,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function companyFromContext(): int
    {
        try {
            $user = auth('api')->user() ?? auth()->user();
            if (! $user) {
                return 0;
            }
            if ($user->type === 'SuperAdmin') {
                $cid = (int) request()->input('company_id', 0);

                return $cid && $cid !== 999 ? $cid : 0;
            }

            return (int) $user->company_id;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function companyFromAddress(?string $email): int
    {
        if (! $email) {
            return 0;
        }
        $cid = EmailOfficeSetting::whereRaw('LOWER(from_email) = ? OR LOWER(username) = ?', [$email, $email])->value('company_id');
        if (! $cid && Schema::hasColumn('servers', 'company_id')) {
            $cid = DB::table('servers')->whereNull('deleted_at')->whereRaw('LOWER(sender_email) = ?', [$email])->where('company_id', '!=', 999)->value('company_id');
        }

        return (int) $cid;
    }
}
