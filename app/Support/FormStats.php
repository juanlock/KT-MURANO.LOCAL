<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Forms dashboard counters (2026-10-24): one row per form and day in
 * form_stats_daily -- views (the form was opened), users (unique visitors),
 * starts (the visitor began typing), submissions (sent), fill_ms / timed
 * (time from the first keystroke to "Send"). The day is the app's
 * (America/New_York). Counting never breaks the form: a failure is only logged.
 */
class FormStats
{
    public const COLUMNS = ['views', 'users', 'starts', 'submissions', 'fill_ms', 'timed'];

    /** Longest filling time that still counts (24 h, like a forgotten tab). */
    public const MAX_FILL_MS = 86400000;

    public static function bump(object $form, string $column, int $amount = 1): void
    {
        if (! in_array($column, self::COLUMNS, true) || $amount <= 0) {
            return;
        }
        try {
            DB::statement(
                "INSERT INTO form_stats_daily (company_id, form_id, day, {$column}) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE {$column} = {$column} + VALUES({$column})",
                [(int) $form->company_id, (int) $form->id, now()->toDateString(), $amount]
            );
        } catch (\Throwable $e) {
            Log::warning('form stats: '.$e->getMessage(), ['form' => $form->id ?? null]);
        }
    }

    /**
     * A view, and a unique visitor the first time this browser + connection
     * opens the form today (a hash in the cache -- no cookie, no personal data stored).
     */
    public static function view(object $form, Request $request): void
    {
        self::bump($form, 'views');
        $visitor = sha1($form->id.'|'.now()->toDateString().'|'.$request->ip().'|'.$request->userAgent().'|'.config('app.key'));
        try {
            if (Cache::add('form-visitor:'.$visitor, 1, now()->addDays(2))) {
                self::bump($form, 'users');
            }
        } catch (\Throwable $e) {
            Log::warning('form stats (visitor): '.$e->getMessage());
        }
    }

    /** A submission + how long the visitor took to fill it (ms from the form's script). */
    public static function submitted(object $form, $fillMs): void
    {
        self::bump($form, 'submissions');
        $ms = (int) $fillMs;
        if ($ms > 0 && $ms <= self::MAX_FILL_MS) {
            self::bump($form, 'fill_ms', $ms);
            self::bump($form, 'timed');
        }
    }
}
