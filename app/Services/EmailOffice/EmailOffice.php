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

    // A supplier's emails that are NOT the bill (checked on the SUBJECT, before
    // BILL -- a statement's body also says "payment due"): they go into the
    // bill's history (the expense), never "To record".
    private const PAYMENT_IN = '/(we([\'’]ve| have)? received your payment|we processed your payment|payment (has been |was )?(received|processed|posted|confirmed|successful)|thanks? (you )?for your payment|payment confirmation|your payment (of|was|has|is complete)|autopay (payment )?(processed|complete)|pago recibido|recibimos tu pago|gracias por tu pago)/iu';

    private const REMINDER_IN = '/(payment reminder|reminder:? your (payment|bill)|payment (is )?due( soon| date)?|due date is (approaching|coming)|payment date is approaching|past due|overdue|final notice|autopay (is )?scheduled|upcoming payment|recordatorio de pago|pago vencido)/iu';

    private const NOTICE_IN = '/(paperless|has your card arrived|card (is )?(on its way|ready to ship)|new card|security alert|fraud alert|verification code|verify your|thanks for verifying|password|sign[- ]in|log ?in alert|update your (profile|information)|terms|privacy|survey|feedback|hear about your experience|newsletter|offer|upgrade|webinar|enroll|enrolling|enrolled|statement credit|pay over time|credit limit|spending limit)/iu';

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
        if (! $partyType && $direction === 'in' && ($sid = self::supplierByName($companyId, self::decodeHeader($m['from_name'] ?? null)))) {
            [$partyType, $partyId] = ['provider', $sid];
        }
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
        // Several accounts with the same sender (two cards of one bank): the
        // "Account ending ..." in the email picks the right supplier.
        if ($direction === 'in' && ($partyType === 'provider' || $supplierId || ! $partyType)) {
            $acc = self::supplierByAccount($companyId, ($m['subject'] ?? '')."\n".mb_substr($text, 0, 5000),
                $partyType === 'provider' ? (int) $partyId : ($supplierId ? (int) $supplierId : null), $m['from_name'] ?? null);
            if ($acc) {
                [$partyType, $partyId] = ['provider', $acc];
            }
        }

        $row = OfficeEmail::create([
            'company_id' => $companyId,
            'direction' => $direction,
            'source' => $m['source'] ?? 'mailbox',
            'message_id' => $mid ? Str::limit($mid, 500, '') : null,
            'message_hash' => $hash,
            'in_reply_to' => isset($m['in_reply_to']) ? Str::limit((string) $m['in_reply_to'], 500, '') : null,
            'subject' => $subjectText = Str::limit(trim((string) self::decodeHeader($m['subject'] ?? '')) ?: '(no subject)', 990, ''),
            'subject_norm' => self::normSubject($subjectText),
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
        // The company's own rules (e.g. TikTok / Facebook -> Social) win over the automatic sorting.
        EmailRules::applyToNew($row);
        // A reminder / payment confirmation of a bill already recorded: into its history.
        self::autoAttachToBill($row);

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
        // Claim is set by hand only (user's rule, 2026-10-05): guessed from
        // words it caught hundreds of ordinary emails. Those used to return
        // 'claim' here (subject first, then body) -- now they stay general.
        // A supplier's payment confirmation / payment reminder / notice: not the
        // bill itself (judged by the subject, see PAYMENT_IN).
        if ($direction === 'in' && $partyType !== 'client') {
            if (preg_match(self::PAYMENT_IN, $subject)) {
                return 'payment';
            }
            if (preg_match(self::REMINDER_IN, $subject)) {
                return 'reminder';
            }
            if (preg_match(self::NOTICE_IN, $subject)) {
                return 'general';
            }
        }
        $hasPdf = collect($attachments)->contains(fn ($a) => str_ends_with(strtolower((string) ($a['name'] ?? '')), '.pdf'));
        // A bill is something WE pay: a customer writing about invoices
        // ("Re: Invoice Created", "updated email for invoices") is not one.
        if ($direction === 'in' && $partyType !== 'client' && (preg_match(self::BILL, $head) || ($hasPdf && $partyType === 'provider'))) {
            return 'bill';
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
        // The company's own mailbox is never a customer / supplier / employee
        // (a contact saved with it would swallow every email the company sends itself).
        $own = self::ownAddresses($companyId);
        $emails = array_values(array_diff($emails, $own));
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

    /**
     * A document created from an email (purchase / quotation / service job...):
     * the email is marked recorded as it, and assigned to that document's
     * customer / supplier. Only an email of the same company.
     */
    public static function linkTo(int $emailId, int $companyId, string $type, int $docId, ?string $partyType = null, ?int $partyId = null): void
    {
        if (! $emailId || ! $companyId || ! $docId || ! \Illuminate\Support\Facades\Schema::hasTable('office_emails')) {
            return;
        }
        $q = OfficeEmail::whereKey($emailId)->where('company_id', $companyId);
        $q->update(array_filter([
            'linked_type' => $type, 'linked_id' => $docId,
            'party_type' => $partyId ? $partyType : null, 'party_id' => $partyId ?: null,
        ], fn ($v) => $v !== null));
        // The email's type follows what it became (unless set by hand):
        // a quotation answers a request, a service job is a work order.
        $category = ['quotation' => 'request', 'service_job' => 'work_order'][$type] ?? null;
        if ($category) {
            (clone $q)->where('category_manual', false)->update(['category' => $category]);
        }
    }

    /**
     * A subject without its reply / forward prefixes ("Re: ", "Fwd: ", "RE: RE: ",
     * "AW:", "[2] Re:"...), spaces collapsed. lower = the stored key
     * (office_emails.subject_norm); false = for display.
     */
    public static function normSubject(?string $subject, bool $lower = true): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $subject));
        while (preg_match('/^(re|fw|fwd|aw|wg|rv|tr|sv|vs)\s*(\[\d+\])?\s*:\s*/iu', $s, $m)) {
            $s = trim(mb_substr($s, mb_strlen($m[0])));
        }

        return mb_substr($lower ? mb_strtolower($s) : $s, 0, 250);
    }

    /**
     * One sender, several accounts (e.g. two American Express cards saved as
     * suppliers "American Express - 652003" and "American Express 11002"): the
     * email's "Account ending: 52003" picks the supplier whose name carries
     * that number (either one ending with the other: 652003 / 52003, 611002 /
     * 11002). Only among suppliers of the same brand (the first word of the
     * current supplier's or the sender's name). Returns the supplier id, or
     * null when the email names no account or no single supplier matches.
     */
    public static function supplierByAccount(?int $companyId, ?string $text, ?int $currentProviderId = null, ?string $senderName = null): ?int
    {
        if (! $companyId || ! preg_match_all('/account\s*(?:ending|number|no\.?|#)(?:\s+in)?[\s:#*xX\-]*(\d{4,})/i', (string) $text, $m)) {
            return null;
        }
        $numbers = array_values(array_unique($m[1]));
        $brandOf = fn (?string $n) => mb_strtolower(trim((string) preg_split('/[\s\-–:,]+/u', trim(trim((string) $n), '"'))[0]));
        $brands = array_filter([
            $currentProviderId ? $brandOf(DB::table('providers')->where('id', $currentProviderId)->value('name')) : null,
            $brandOf($senderName),
        ], fn ($b) => mb_strlen($b) >= 3);
        if (! $brands) {
            return null;
        }
        $hits = [];
        $last4 = []; // replacement cards keep the last 4 digits (651005 -> 771005 / 71005)
        foreach (DB::table('providers')->where('company_id', $companyId)->whereNull('deleted_at')->get(['id', 'name']) as $p) {
            if (! in_array($brandOf($p->name), $brands, true) || ! preg_match_all('/\d{4,}/', (string) $p->name, $pm)) {
                continue;
            }
            foreach ($pm[0] as $d) {
                foreach ($numbers as $n) {
                    if (str_ends_with($d, $n) || str_ends_with($n, $d)) {
                        $hits[$p->id] = true;
                    } elseif (substr($d, -4) === substr($n, -4)) {
                        $last4[$p->id] = true;
                    }
                }
            }
        }
        if (! $hits && count($last4) === 1) {
            $hits = $last4;
        }

        return count($hits) === 1 ? (int) array_key_first($hits) : null;
    }

    /** Days a bill's reminders / payment confirmations can come after it (when no next bill bounds it). */
    public const HISTORY_DAYS = 45;

    /**
     * A bill's history: the same supplier's (= same account / card) payment
     * reminders and payment confirmations from a few days before the bill
     * until the supplier's NEXT bill (or HISTORY_DAYS), not recorded yet.
     */
    public static function billHistoryQuery(OfficeEmail $bill)
    {
        $from = $bill->sent_at->copy()->subDays(3);
        $next = OfficeEmail::where('company_id', $bill->company_id)->where('direction', 'in')
            ->where('party_type', 'provider')->where('party_id', $bill->party_id)->where('category', 'bill')
            ->where('id', '!=', $bill->id)->where('sent_at', '>', $bill->sent_at)->min('sent_at');
        $until = $next ? \Carbon\Carbon::parse($next) : $bill->sent_at->copy()->addDays(self::HISTORY_DAYS);

        return OfficeEmail::where('company_id', $bill->company_id)->where('direction', 'in')
            ->where('party_type', 'provider')->where('party_id', $bill->party_id)
            ->whereIn('category', ['reminder', 'payment'])->whereNull('linked_type')
            ->where('id', '!=', $bill->id)->whereBetween('sent_at', [$from, $until]);
    }

    /** Links emails to the bill's expense as its history (role = reminder / payment). */
    public static function attachHistory(OfficeEmail $bill, array $ids): int
    {
        if ($bill->linked_type !== 'expense' || ! $bill->linked_id || ! $ids) {
            return 0;
        }
        $n = 0;
        foreach (self::billHistoryQuery($bill)->whereIn('id', $ids)->get(['id', 'category']) as $e) {
            $e->update(['linked_type' => 'expense', 'linked_id' => $bill->linked_id, 'link_role' => $e->category]);
            $n++;
        }

        return $n;
    }

    /**
     * A new reminder / payment confirmation from a supplier whose last bill
     * is already an expense: joins that expense's history by itself.
     */
    public static function autoAttachToBill(OfficeEmail $e): void
    {
        if ($e->direction !== 'in' || $e->party_type !== 'provider' || ! $e->party_id || $e->linked_type
            || ! in_array($e->category, ['reminder', 'payment'], true)) {
            return;
        }
        $bill = OfficeEmail::where('company_id', $e->company_id)->where('direction', 'in')
            ->where('party_type', 'provider')->where('party_id', $e->party_id)->where('category', 'bill')
            ->where('sent_at', '<=', $e->sent_at->copy()->addDays(3))
            ->where('sent_at', '>=', $e->sent_at->copy()->subDays(self::HISTORY_DAYS))
            ->orderByDesc('sent_at')->first();
        if ($bill && $bill->linked_type === 'expense' && $bill->linked_id) {
            $e->update(['linked_type' => 'expense', 'linked_id' => $bill->linked_id, 'link_role' => $e->category]);
        }
    }

    /** The company's own mailbox addresses (Email Office settings), lower case. */
    public static function ownAddresses(?int $companyId): array
    {
        static $cache = [];
        if (! $companyId) {
            return [];
        }

        return $cache[$companyId] ??= array_values(array_unique(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            (array) DB::table('email_office_settings')->where('company_id', $companyId)->first(['from_email', 'username'])
        ))));
    }

    /**
     * A supplier whose NAME is the sender's display name (quotes stripped,
     * any case). Companies like AT&T send every bill from a new address under
     * the same name, so a supplier created from one of their emails keeps
     * matching the next ones. Suppliers only -- customers match by email only.
     */
    public static function supplierByName(?int $companyId, ?string $fromName): ?int
    {
        $name = trim(trim((string) $fromName), '"');
        if (! $companyId || mb_strlen($name) < 3) {
            return null;
        }
        $id = DB::table('providers')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])->value('id');

        return $id ? (int) $id : null;
    }

    /** Re-match emails without a party (after a customer/supplier gets an email address). */
    public static function rematch(int $companyId): int
    {
        $n = 0;
        OfficeEmail::where('company_id', $companyId)->whereNull('party_type')->orderBy('id')->chunkById(500, function ($rows) use ($companyId, &$n) {
            foreach ($rows as $r) {
                $emails = $r->direction === 'in' ? [$r->from_email] : explode(',', (string) $r->to_emails);
                [$t, $id] = self::matchParty($companyId, $emails);
                if (! $t && $r->direction === 'in' && ($sid = self::supplierByName($companyId, $r->from_name))) {
                    [$t, $id] = ['provider', $sid];
                }
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
