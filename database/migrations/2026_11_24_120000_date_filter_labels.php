<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The standard date filter (user's rule, 2026-11-24): the Spanish names say "Semana pasada", "Mes pasado", "Año pasado"
 * (were "La semana pasada", "Último mes", "Último año"), and the new "Día" button (DayPresetControl) has its texts.
 * By key; safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Last_Week' => ['Last week', 'Semana pasada'],
        'Last_Month' => ['Last month', 'Mes pasado'],
        'Last_Year' => ['Last year', 'Año pasado'],
        'Day' => ['Day', 'Día'],
    ];

    public function up(): void
    {
        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->update(['es' => $es, 'updated_at' => now()]);
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                ->where(fn ($q) => $q->whereNull('en')->orWhere('en', '')->orWhere('en', $key))->update(['en' => $en]);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        foreach (['Last_Week' => 'La semana pasada', 'Last_Month' => 'Último mes', 'Last_Year' => 'Último año'] as $key => $es) {
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->update(['es' => $es, 'updated_at' => now()]);
        }
    }
};
