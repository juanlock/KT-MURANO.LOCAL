<?php

namespace App\Services;

use App\Support\CompanyMail;
use App\Support\FormSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Forms module emails (2026-10-24), through the company's own mail server:
 *  - submitted(): "new submission" to the company -- the form's addresses
 *    (Settings -> Notify, as many as needed) or, when none, the company's admins
 *    (CompanyMail::recipients). Reply-To = the visitor's email field.
 *  - confirmVisitor(): the receipt for whoever filled the form (Settings -> Receipt
 *    to the visitor), to the email they typed, with the submission number and,
 *    optionally, what they sent; hidden copy to staff. E.g. the complaints book's
 *    copy for the consumer.
 * A mail failure never loses the submission (it is already saved).
 */
class FormNotifier
{
    private static function reserved(string $e): bool
    {
        return (bool) preg_match('/(\.(test|example|invalid|localhost)|@example\.(com|net|org))$/i', $e);
    }

    /** [label => text] of what was sent, in the form's order (no hidden fields). */
    private static function rows(array $fields, object $sub): array
    {
        $data = json_decode((string) $sub->data, true) ?: [];
        $rows = [];
        foreach (FormSchema::inputs($fields) as $f) {
            if ($f['type'] === 'hidden') {
                continue;
            }
            $label = trim(rtrim($f['label'] ?: $f['key'], ':'));
            $rows[$label] = FormSchema::text($data[$f['key']] ?? '') ?: '-';
        }

        return $rows;
    }

    private static function html(string $intro, array $rows, ?string $button, ?string $buttonText, string $color, string $company): string
    {
        return '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:600px">'
            .($intro !== '' ? '<p>'.nl2br(e($intro)).'</p>' : '')
            .($rows ? '<table style="border-collapse:collapse;margin:12px 0">'
                .collect($rows)->map(fn ($v, $k) => '<tr><td style="padding:4px 16px 4px 0;color:#666;vertical-align:top">'.e($k).'</td><td style="padding:4px 0;font-weight:600">'.nl2br(e($v)).'</td></tr>')->implode('')
                .'</table>' : '')
            .($button ? '<p><a href="'.e($button).'" style="display:inline-block;background:'.e($color).';color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px">'.e($buttonText).'</a></p>' : '')
            .'<p style="color:#888;font-size:12px;margin-top:24px">'.e($company).'</p></div>';
    }

    /** @return array<string> addresses it went to */
    private static function send(int $cid, array $to, array $bcc, string $fromName, string $subject, string $html, string $text, ?string $replyTo, int $formId): array
    {
        [$mailer, $from] = CompanyMail::mailer($cid);
        $sent = [];
        foreach ($to as $email) {
            try {
                $mailer->html($html, function ($m) use ($email, $bcc, $from, $fromName, $subject, $replyTo, $text) {
                    $m->to($email)->from($from ?: config('mail.from.address'), $fromName)->subject($subject);
                    if ($bcc) {
                        $m->bcc($bcc);
                    }
                    if ($replyTo) {
                        $m->replyTo($replyTo);
                    }
                    $m->getSymfonyMessage()->text($text);
                });
                $sent[] = $email;
                $bcc = [];   // the hidden copy goes once
            } catch (\Throwable $e) {
                Log::warning('form mail failed: '.$e->getMessage(), ['form' => $formId, 'to' => $email]);
            }
        }

        return $sent;
    }

    /** [label => text] of a submission with its number and date first (PDF, print, resend). */
    public static function summary(object $form, array $fields, array $s, object $sub): array
    {
        $es = $s['language'] === 'es';
        $rows = [($es ? 'Formulario' : 'Form') => $form->name, ($es ? 'Número' : 'Number') => $s['number_prefix'].$sub->number,
            ($es ? 'Fecha' : 'Date') => (string) $sub->created_at] + self::rows($fields, $sub);
        if (! empty($sub->attended_at)) {
            $rows[$es ? 'Atendido' : 'Attended'] = $sub->attended_at.(! empty($sub->attended_note) ? "\n".$sub->attended_note : '');
        }

        return $rows;
    }

    /**
     * Submissions -> "Send by email" (2026-11-01): the submission to the addresses
     * staff type, through the company's mail server. @return array<string> sent to
     */
    public static function resend(object $form, array $fields, array $s, object $sub, array $to, string $message = ''): array
    {
        $cid = (int) $form->company_id;
        $to = array_values(array_unique(array_filter($to, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! self::reserved($e))));
        if (! $to) {
            return [];
        }
        $company = trim((string) DB::table('companies')->where('id', $cid)->value('name')) ?: 'Forms';
        $rows = self::summary($form, $fields, $s, $sub);
        $subject = $form->name.' - '.$s['number_prefix'].$sub->number;
        $html = self::html($message, $rows, null, null, '#1677ff', $company);
        $text = ($message !== '' ? $message."\n\n" : '').collect($rows)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")."\n\n--\n{$company}";

        return self::send($cid, $to, [], $s['from_name'] ?: $company, $subject, $html, $text, null, (int) $form->id);
    }

    /** The submitter's email: the form's reply-to field, else its first email field with a valid value. */
    public static function senderEmail(array $fields, array $s, object $sub): ?string
    {
        $data = json_decode((string) $sub->data, true) ?: [];
        $keys = array_merge($s['reply_to_field'] ? [$s['reply_to_field']] : [],
            array_column(array_filter(FormSchema::inputs($fields), fn ($f) => $f['type'] === 'email'), 'key'));
        foreach ($keys as $k) {
            $e = trim((string) ($data[$k] ?? ''));
            if (filter_var($e, FILTER_VALIDATE_EMAIL) && ! self::reserved($e)) {
                return $e;
            }
        }

        return null;
    }

    /**
     * Submissions -> "Reply" (2026-11-03): staff write to the person who sent the form,
     * from the company's mail server; their message is quoted below. Reply-To = the
     * user who answers. @return array<string> sent to
     */
    public static function reply(object $form, array $fields, array $s, object $sub, string $subject, string $message, ?string $replyTo): array
    {
        $to = self::senderEmail($fields, $s, $sub);
        if (! $to) {
            return [];
        }
        $cid = (int) $form->company_id;
        $company = trim((string) DB::table('companies')->where('id', $cid)->value('name')) ?: 'Forms';
        $es = $s['language'] === 'es';
        $rows = self::rows($fields, $sub);
        $quote = '<div style="margin-top:20px;padding-top:10px;border-top:1px solid #ddd;color:#666;font-size:13px">'
            .e(($es ? 'Su mensaje del ' : 'Your message of ').$sub->created_at).':'
            .'<table style="border-collapse:collapse;margin:8px 0">'
            .collect($rows)->map(fn ($v, $k) => '<tr><td style="padding:2px 12px 2px 0;vertical-align:top">'.e($k).'</td><td style="padding:2px 0">'.nl2br(e($v)).'</td></tr>')->implode('')
            .'</table></div>';
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:600px"><p>'.nl2br(e($message)).'</p>'
            .$quote.'<p style="color:#888;font-size:12px;margin-top:24px">'.e($company).'</p></div>';
        $text = $message."\n\n-- ".($es ? 'Su mensaje del ' : 'Your message of ').$sub->created_at.":\n"
            .collect($rows)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")."\n\n--\n{$company}";

        return self::send($cid, [$to], [], $s['from_name'] ?: $company, $subject, $html, $text, $replyTo, (int) $form->id);
    }

    public static function submitted(object $form, array $fields, array $s, object $sub): array
    {
        $cid = (int) $form->company_id;
        $to = $s['notify_emails'] ?: array_column(CompanyMail::recipients($cid), 'email');
        $to = array_values(array_unique(array_filter($to, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! self::reserved($e))));
        if (! $to) {
            return [];
        }
        $company = trim((string) DB::table('companies')->where('id', $cid)->value('name')) ?: 'Forms';
        $data = json_decode((string) $sub->data, true) ?: [];
        $ref = $s['number_prefix'].$sub->number;
        $rows = ['Form' => $form->name, 'Number' => $ref, 'Date' => (string) $sub->created_at] + self::rows($fields, $sub);
        if ($sub->page_url) {
            $rows['Page'] = $sub->page_url;
        }
        $replyTo = $s['reply_to_field'] && filter_var($data[$s['reply_to_field']] ?? '', FILTER_VALIDATE_EMAIL) ? $data[$s['reply_to_field']] : null;
        $subject = str_replace(['{form}', '{number}'], [$form->name, $ref], $s['notify_subject'] ?: 'New submission: {form}');
        $link = url('/next/forms/'.$form->id.'/submissions?open='.$sub->id);
        $es = $s['language'] === 'es';
        $html = self::html($es ? 'Se recibió un nuevo envío del formulario.' : 'A new form submission was received.', $rows, $link, $es ? 'Abrir en Murano' : 'Open in Murano', '#1677ff', $company);
        $text = collect($rows)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")."\n\n{$link}\n\n--\n{$company}";

        return self::send($cid, $to, [], $s['from_name'] ?: $company, $subject, $html, $text, $replyTo, (int) $form->id);
    }

    public static function confirmVisitor(object $form, array $fields, array $s, object $sub): array
    {
        if (! $s['confirm_email'] || ! $s['confirm_field']) {
            return [];
        }
        $data = json_decode((string) $sub->data, true) ?: [];
        $to = trim((string) ($data[$s['confirm_field']] ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL) || self::reserved($to)) {
            return [];
        }
        $cid = (int) $form->company_id;
        $company = trim((string) DB::table('companies')->where('id', $cid)->value('name')) ?: 'Forms';
        $es = $s['language'] === 'es';
        $ref = $s['number_prefix'].$sub->number;
        $subject = str_replace(['{form}', '{number}'], [$form->name, $ref], $s['confirm_subject'] ?: ($s['title'] ?: $form->name).' - '.$ref);
        $intro = trim($s['confirm_message']) ?: ($es ? 'Hemos recibido su envío. Este es su comprobante.' : 'We received your submission. This is your receipt.');
        $rows = [($es ? 'Número' : 'Number') => $ref, ($es ? 'Fecha' : 'Date') => (string) $sub->created_at]
            + ($s['confirm_data'] ? self::rows($fields, $sub) : []);
        $html = self::html(str_replace('{number}', $ref, $intro), $rows, null, null, $s['primary_color'], $company);
        $text = str_replace('{number}', $ref, $intro)."\n\n".collect($rows)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")."\n\n--\n{$company}";
        $replyTo = $s['notify_emails'][0] ?? null;

        return self::send($cid, [$to], $s['confirm_bcc'], $s['from_name'] ?: $company, $subject, $html, $text, $replyTo, (int) $form->id);
    }
}
