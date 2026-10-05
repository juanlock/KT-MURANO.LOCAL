<?php

namespace App\Services\EmailOffice;

use App\Models\OfficeEmail;
use App\Support\CompanyFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Email Office core: saves an email once (per company + Message-ID), sorts
 * it and matches it to who it's from / to.
 *
 * Sorting (category, unless someone changed it by hand):
 *  - reminder: sent emails about a reminder / due payment / appointment;
 *  - bill: invoices, bills, receipts, statements, "amount due" -- or any
 *    email with a PDF from a supplier (it's how bills arrive);
 *  - claim: complaints, refunds, "not working", support tickets ([HD-...]);
 *  - general: everything else.
 *
 * Matching (party): received -> the sender; sent -> the first recipient that
 * is a customer (clients.email / additional_emails), else a supplier
 * (providers.email), else an employee -- of the same company.
 */
class EmailOffice
{
    public const ATTACH_MAX_BYTES = 10 * 1024 * 1024;

    private const BILL = '/\b(invoice|invoices|bill|billing|receipt|statement|amount due|payment due|past due|balance due|factura|recibo|cuenta de cobro|estado de cuenta|monto adeudado|facture|reçu)\b/iu';

    private const CLAIM = '/\b(complaint|complain|claim|refund|not working|doesn\'t work|broken|damaged|defective|problem|issue|support|warranty|reclamo|queja|reclamación|reembolso|no funciona|dañado|garantía|réclamation|plainte)\b|\[HD-\d+\]/iu';

    private const REMINDER = '/\b(reminder|remind|overdue|due date|payment due|upcoming appointment|recordatorio|vencid[oa]|vence|rappel)\b/iu';

    /**
     * Saves one email unless it's already there. $m: direction, source,
     * message_id, subject, from_email, from_name, to (array of emails),
     * cc, sent_at, body_text, body_html, attachments (stored files), status, error.
     */
    public static function record(?int $companyId, array $m): ?OfficeEmail
    {
        $mid = trim((string) ($m['message_id'] ?? '')) ?: null;
        $hash = sha1($mid ?: ($m['direction'].'|'.($m['from_email'] ?? '').'|'.($m['subject'] ?? '').'|'.($m['sent_at'] ?? now())));
        if (OfficeEmail::where('company_id', $companyId)->where('message_hash', $hash)->exists()) {
            return null;
        }
        $to = self::emails($m['to'] ?? []);
        $cc = self::emails($m['cc'] ?? []);
        $text = (string) ($m['body_text'] ?? '');
        if ($text === '' && ! empty($m['body_html'])) {
            $text = self::htmlToText((string) $m['body_html']);
        }
        $from = strtolower(trim((string) ($m['from_email'] ?? '')));
        $direction = $m['direction'] === 'out' ? 'out' : 'in';
        [$partyType, $partyId] = self::matchParty($companyId, $direction === 'in' ? [$from] : $to);
        $attachments = $m['attachments'] ?? [];
        // The same address can be a customer AND a supplier: for sorting, a
        // sender that is a supplier counts as one, and a bill belongs to the supplier.
        $supplierId = $direction === 'in' && $from && $companyId
            ? DB::table('providers')->where('company_id', $companyId)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$from])->value('id')
            : null;
        $category = self::classify($direction, (string) ($m['subject'] ?? ''), $text, $attachments, $supplierId ? 'provider' : $partyType);
        if ($category === 'bill' && $supplierId) {
            [$partyType, $partyId] = ['provider', (int) $supplierId];
        }

        $row = OfficeEmail::create([
            'company_id' => $companyId,
            'direction' => $direction,
            'source' => $m['source'] ?? 'mailbox',
            'message_id' => $mid ? Str::limit($mid, 500, '') : null,
            'message_hash' => $hash,
            'in_reply_to' => isset($m['in_reply_to']) ? Str::limit((string) $m['in_reply_to'], 500, '') : null,
            'subject' => Str::limit(trim((string) self::decodeHeader($m['subject'] ?? '')) ?: '(no subject)', 990, ''),
            'from_email' => $from ?: null,
            'from_name' => isset($m['from_name']) ? Str::limit(trim((string) self::decodeHeader($m['from_name'])), 250, '') : null,
            'to_emails' => implode(', ', $to) ?: null,
            'cc_emails' => implode(', ', $cc) ?: null,
            'sent_at' => $m['sent_at'] ?? now(),
            'body_text' => Str::limit($text, 200000, ''),
            'body_html' => isset($m['body_html']) ? Str::limit((string) $m['body_html'], 500000, '') : null,
            'snippet' => Str::limit(trim(preg_replace('/\s+/u', ' ', $text)), 280),
            'attachments' => $attachments ?: null,
            'category' => $category,
            'party_type' => $partyType,
            'party_id' => $partyId,
            'is_read' => $direction === 'out',
            'status' => $m['status'] ?? 'ok',
            'error' => $m['error'] ?? null,
        ]);

        return $row;
    }

    /**
     * "=?utf-8?B?...?=" / "=?utf-8?q?...?=" left undecoded by the IMAP library
     * (it misses some multi-part encoded headers) -> readable text.
     */
    public static function decodeHeader(?string $value): ?string
    {
        if ($value === null || ! str_contains($value, '=?')) {
            return $value;
        }
        $out = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($out === false || $out === '' || str_contains($out, '=?')) {
            $out = mb_decode_mimeheader($value);
        }

        return trim((string) $out) !== '' ? trim($out) : $value;
    }

    /** Already imported (same company + Message-ID)? Checked BEFORE saving attachments. */
    public static function alreadyHas(?int $companyId, ?string $messageId): bool
    {
        $mid = trim((string) $messageId);

        return $mid !== '' && OfficeEmail::where('company_id', $companyId)->where('message_hash', sha1($mid))->exists();
    }

    public static function classify(string $direction, string $subject, string $text, array $attachments, ?string $partyType): string
    {
        $head = $subject."\n".mb_substr($text, 0, 3000);
        if ($direction === 'out' && preg_match(self::REMINDER, $subject)) {
            return 'reminder';
        }
        if (preg_match(self::CLAIM, $subject)) {
            return 'claim';
        }
        $hasPdf = collect($attachments)->contains(fn ($a) => str_ends_with(strtolower((string) ($a['name'] ?? '')), '.pdf'));
        if ($direction === 'in' && (preg_match(self::BILL, $head) || ($hasPdf && $partyType === 'provider'))) {
            return 'bill';
        }
        if (preg_match(self::CLAIM, $head)) {
            return 'claim';
        }
        if ($direction === 'out' && preg_match(self::REMINDER, $head)) {
            return 'reminder';
        }

        return 'general';
    }

    /** [party_type, party_id] for the first address that is a customer / supplier / employee of the company. */
    public static function matchParty(?int $companyId, array $emails): array
    {
        $emails = array_values(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $emails)));
        if (! $companyId || ! $emails) {
            return [null, null];
        }
        foreach ($emails as $e) {
            $id = DB::table('clients')->where('company_id', $companyId)->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$e])->orWhere('additional_emails', 'like', '%"'.$e.'"%'))
                ->value('id');
            if ($id) {
                return ['client', (int) $id];
            }
        }
        foreach ($emails as $e) {
            if ($id = DB::table('providers')->where('company_id', $companyId)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$e])->value('id')) {
                return ['provider', (int) $id];
            }
        }
        foreach ($emails as $e) {
            if ($id = DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$e])->value('id')) {
                return ['employee', (int) $id];
            }
        }

        return [null, null];
    }

    /** Re-match emails without a party (after a customer/supplier gets an email address). */
    public static function rematch(int $companyId): int
    {
        $n = 0;
        OfficeEmail::where('company_id', $companyId)->whereNull('party_type')->orderBy('id')->chunkById(500, function ($rows) use ($companyId, &$n) {
            foreach ($rows as $r) {
                $emails = $r->direction === 'in' ? [$r->from_email] : explode(',', (string) $r->to_emails);
                [$t, $id] = self::matchParty($companyId, $emails);
                if ($t) {
                    $r->update(['party_type' => $t, 'party_id' => $id]);
                    $n++;
                }
            }
        });

        return $n;
    }

    /** Attachments: public/files/company_{id}/email-office/{Y}/{m}/ (project standard), private. */
    public const FILES_MODULE = 'email-office';

    /** Stores one attachment for the company; returns its descriptor. */
    public static function storeAttachment(int $companyId, string $name, string $bytes, ?string $mime): ?array
    {
        if ($bytes === '' || strlen($bytes) > self::ATTACH_MAX_BYTES) {
            return null;
        }
        $path = CompanyFiles::putBytes($companyId, self::FILES_MODULE, $name ?: 'attachment', $bytes, true);

        return ['name' => $name ?: 'attachment', 'size' => strlen($bytes), 'mime' => $mime, 'path' => $path];
    }

    /** Lower-case unique valid addresses from strings / ['email' => ...] / "Name <a@b>" lists. */
    public static function emails($list): array
    {
        $out = [];
        foreach ((array) $list as $k => $v) {
            $e = is_array($v) ? ($v['email'] ?? $v['mail'] ?? '') : (is_string($k) && filter_var($k, FILTER_VALIDATE_EMAIL) ? $k : $v);
            if (is_string($e) && preg_match('/<([^>]+)>/', $e, $m)) {
                $e = $m[1];
            }
            $e = strtolower(trim((string) $e));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[$e] = true;
            }
        }

        return array_keys($out);
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('~<(script|style|head)\b.*?</\1>~is', '', $html);
        $html = preg_replace('~<br\s*/?>|</(p|div|li|tr|h[1-6])>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
