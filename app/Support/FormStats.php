<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Forms dashboard counters (2026-10-24): one row per form and day in
 * form_stats_daily -- views (the form was opened), starts (the visitor began
 * typing), submissions (it was sent). The day is the app's (America/New_York).
 * Counting never breaks the form: a failure is only logged.
 */
class FormStats
{
    public const COLUMNS = ['views', 'starts', 'submissions'];

    public static function bump(object $form, string $column): void
    {
        if (! in_array($column, self::COLUMNS, true)) {
            return;
        }
        try {
            DB::statement(
                "INSERT INTO form_stats_daily (company_id, form_id, day, {$column}) VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
                [(int) $form->company_id, (int) $form->id, now()->toDateString()]
            );
        } catch (\Throwable $e) {
            Log::warning('form stats: '.$e->getMessage(), ['form' => $form->id ?? null]);
        }
    }
}
