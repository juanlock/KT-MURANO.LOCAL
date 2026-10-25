<?php

namespace App\Services;

use App\Support\CompanyMail;
use App\Support\FormSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Forms module (2026-10-24): "new submission" email to the company -- the form's
 * own addresses (Settings -> Notify) or, when none, the company's admins
 * (CompanyMail::recipients) -- through the company's mail server. Reply-To = the
 * visitor's email field when the form names one. A mail failure never loses
 * the submission (it is already saved).
 */
class FormNotifier
{
    public static function submitted(object $form, array $fields, array $s, object $sub): array
    {
        $cid = (int) $form->company_id;
        $to = $s['notify_emails'] ?: array_column(CompanyMail::recipients($cid), 'email');
        $to = array_values(array_unique(array_filter($to, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
            && ! preg_match('/(\.(test|example|invalid|localhost)|@example\.(com|net|org))$/i', $e))));
        if (! $to) {
            return [];
        }
        $company = trim((string) DB::table('companies')->where('id', $cid)->value('name')) ?: 'Forms';
        $data = json_decode((string) $sub->data, true) ?: [];
        $ref = $s['number_prefix'].$sub->number;
        $rows = ['Form' => $form->name, 'Number' => $ref, 'Date' => (string) $sub->created_at];
        foreach (FormSchema::inputs($fields) as $f) {
            if ($f['type'] === 'hidden') {
                continue;
            }
            $label = trim(rtrim($f['label'] ?: $f['key'], ':'));
            $rows[$label] = FormSchema::text($data[$f['key']] ?? '') ?: '-';
        }
        if ($sub->page_url) {
            $rows['Page'] = $sub->page_url;
        }
        $replyTo = $s['reply_to_field'] && filter_var($data[$s['reply_to_field']] ?? '', FILTER_VALIDATE_EMAIL) ? $data[$s['reply_to_field']] : null;
        $subject = str_replace(['{form}', '{number}'], [$form->name, $ref], $s['notify_subject'] ?: 'New submission: {form}');
        $link = url('/next/forms/'.$form->id.'/submissions?open='.$sub->id);

        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:600px">'
            .'<p>'.e($s['language'] === 'es' ? 'Se recibió un nuevo envío del formulario.' : 'A new form submission was received.').'</p>'
            .'<table style="border-collapse:collapse;margin:12px 0">'
            .collect($rows)->map(fn ($v, $k) => '<tr><td style="padding:4px 16px 4px 0;color:#666;vertical-align:top">'.e($k).'</td><td style="padding:4px 0;font-weight:600">'.nl2br(e($v)).'</td></tr>')->implode('')
            .'</table><p><a href="'.e($link).'" style="display:inline-block;background:#1677ff;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px">'
            .e($s['language'] === 'es' ? 'Abrir en Murano' : 'Open in Murano').'</a></p>'
            .'<p style="color:#888;font-size:12px;margin-top:24px">'.e($company).'</p></div>';
        $text = collect($rows)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")."\n\n{$link}\n\n--\n{$company}";

        [$mailer, $from] = CompanyMail::mailer($cid);
        $sent = [];
        foreach ($to as $email) {
            try {
                $mailer->html($html, function ($m) use ($email, $from, $company, $subject, $replyTo, $text) {
                    $m->to($email)->from($from ?: config('mail.from.address'), $company)->subject($subject);
                    if ($replyTo) {
                        $m->replyTo($replyTo);
                    }
                    $m->getSymfonyMessage()->text($text);
                });
                $sent[] = $email;
            } catch (\Throwable $e) {
                Log::warning('form notification failed: '.$e->getMessage(), ['form' => $form->id, 'to' => $email]);
            }
        }

        return $sent;
    }
}
