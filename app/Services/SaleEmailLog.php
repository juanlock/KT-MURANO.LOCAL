<?php

namespace App\Services;

use App\Mail\CustomEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Sends a sale's email (invoice / payment reminder / POS receipt) and records
 * it in sale_email_logs -- always, whether Email Office is on or not -- so the
 * sale and the Sales list can show "Sent Oct 1 · Reminders: 2 (last Oct 20)".
 *
 * The email carries X-Sale-Email-Log: Email Office's LogSentEmail listener
 * then links its copy (Email Office -> Sent) to the sale and back to this log.
 * A failed send is recorded as failed and the exception re-thrown.
 */
class SaleEmailLog
{
    public const KINDS = ['invoice', 'reminder', 'receipt'];

    /** @param  array  $email  CustomEmail data (subject, body, company_name, attachments) */
    public static function send(object $sale, string $kind, array $recipients, array $email): void
    {
        $logId = self::start($sale, $kind, $recipients, (string) ($email['subject'] ?? ''));
        if ($logId) {
            $email['headers'] = ($email['headers'] ?? []) + ['X-Sale-Email-Log' => (string) $logId];
        }
        try {
            Mail::to($recipients)->send(new CustomEmail($email));
            self::finish($logId, 'ok');
        } catch (\Throwable $e) {
            self::finish($logId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private static function start(object $sale, string $kind, array $recipients, string $subject): ?int
    {
        try {
            if (! Schema::hasTable('sale_email_logs')) {
                return null;
            }

            return (int) DB::table('sale_email_logs')->insertGetId([
                'company_id' => $sale->company_id ?? null,
                'sale_id' => $sale->id,
                'kind' => in_array($kind, self::KINDS, true) ? $kind : 'invoice',
                'recipients' => mb_substr(implode(', ', array_map('strtolower', array_map('trim', $recipients))), 0, 2000),
                'subject' => mb_substr($subject, 0, 990),
                'user_id' => Auth::id(),
                'sent_at' => now(),
                'status' => 'sending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // the log never blocks the email

            return null;
        }
    }

    private static function finish(?int $logId, string $status, ?string $error = null): void
    {
        if (! $logId) {
            return;
        }
        try {
            DB::table('sale_email_logs')->where('id', $logId)->update([
                'status' => $status, 'error' => $error ? mb_substr($error, 0, 1000) : null, 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Per sale: first invoice email, reminders count / last one, last email of
     * any kind (ok sends only) -- for a page of sales, one grouped query.
     *
     * @return array<int, array{emailed_at: ?string, reminders: int, last_reminder_at: ?string, last_emailed_at: ?string}>
     */
    public static function summary(array $saleIds): array
    {
        if (! $saleIds || ! Schema::hasTable('sale_email_logs')) {
            return [];
        }
        $rows = DB::table('sale_email_logs')->whereIn('sale_id', $saleIds)->where('status', 'ok')
            ->selectRaw("sale_id,
                MIN(CASE WHEN kind <> 'reminder' THEN sent_at END) as emailed_at,
                SUM(kind = 'reminder') as reminders,
                MAX(CASE WHEN kind = 'reminder' THEN sent_at END) as last_reminder_at,
                MAX(sent_at) as last_emailed_at")
            ->groupBy('sale_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->sale_id] = [
                'emailed_at' => $r->emailed_at, 'reminders' => (int) $r->reminders,
                'last_reminder_at' => $r->last_reminder_at, 'last_emailed_at' => $r->last_emailed_at,
            ];
        }

        return $out;
    }

    /**
     * Per sale: every email sent (ok), oldest first -- [['kind', 'at']] -- for
     * the Sales list's "Other Due" window (invoice + each reminder, with dates).
     *
     * @return array<int, array<int, array{kind: string, at: string}>>
     */
    public static function history(array $saleIds): array
    {
        if (! $saleIds || ! Schema::hasTable('sale_email_logs')) {
            return [];
        }
        $out = [];
        foreach (DB::table('sale_email_logs')->whereIn('sale_id', $saleIds)->where('status', 'ok')
            ->orderBy('sent_at')->get(['sale_id', 'kind', 'sent_at']) as $r) {
            $out[(int) $r->sale_id][] = ['kind' => $r->kind, 'at' => $r->sent_at];
        }

        return $out;
    }
}
