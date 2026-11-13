<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The date filter's buttons in one style (2026-11-13: "hoy" in lower case next to "Esta Semana", "Último Mes"):
 * sentence case in both languages. Only a text still as it came is changed. Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        // key => [column, old, new]
        ['Today', 'es', 'hoy', 'Hoy'],
        ['This_Week', 'es', 'Esta Semana', 'Esta semana'],
        ['Last_Month', 'es', 'Último Mes', 'Último mes'],
        ['Last_Year', 'es', 'Último Año', 'Último año'],
        ['This_Week', 'en', 'This Week', 'This week'],
        ['Last_Month', 'en', 'Last Month', 'Last month'],
        ['Last_Year', 'en', 'Last Year', 'Last year'],
    ];

    public function up(): void
    {
        $this->apply(false);
    }

    public function down(): void
    {
        $this->apply(true);
    }

    private function apply(bool $back): void
    {
        foreach (self::TEXTS as [$key, $col, $old, $new]) {
            [$from, $to] = $back ? [$new, $old] : [$old, $new];
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->whereRaw("`$col` = ? COLLATE utf8mb4_bin", [$from])
                ->update([$col => $to, 'updated_at' => now()]);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }
};
