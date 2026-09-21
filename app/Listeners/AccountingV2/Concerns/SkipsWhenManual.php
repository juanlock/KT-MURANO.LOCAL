<?php

namespace App\Listeners\AccountingV2\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

trait SkipsWhenManual
{
    protected function shouldSkipAutomation(): bool
    {
        // Settings toggle wins when explicitly saved (Settings -> Features);
        // null or a missing column falls back to the config/env value.
        try {
            $flag = DB::table('settings')->value('auto_journal_enabled');
            if ($flag !== null) {
                return ! (bool) $flag;
            }
        } catch (\Throwable $e) {
            // column not migrated yet
        }

        return ! Config::get('accounting_v2.auto_generate_journals', false);
    }

    protected function shouldSkipForModel($model): bool
    {
        if ($this->shouldSkipAutomation()) {
            return true;
        }

        $baseline = Config::get('accounting_v2.baseline_date');
        if (! $baseline || ! $model) {
            return false;
        }

        try {
            $createdAt = $model->created_at ?? null;
            if (! $createdAt) {
                return false;
            }

            return Carbon::parse($createdAt)->lt(Carbon::parse($baseline)->startOfDay());
        } catch (\Throwable $e) {
            return false;
        }
    }
}
