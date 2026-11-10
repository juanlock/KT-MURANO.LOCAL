<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * User's rules, 2026-11-10:
 *  - The alerts bell is each company's choice: System Settings -> General -> Alerts, OFF in every company;
 *    turning it on asks which alerts to show (settings.alerts_types).
 *  - No language button in the top bar anywhere (it repeats the language in Settings): show_language off.
 * Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Alerts_Show' => ['Show alerts', 'Mostrar alertas'],
        'Alerts_Help' => ['The bell in the top bar with what is waiting for action. Pick which alerts it shows.', 'La campana de la barra superior con lo que espera una acción. Elija qué alertas muestra.'],
        'Alerts_Pick' => ['Pick at least one alert to show.', 'Elija al menos una alerta para mostrar.'],
    ];

    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            if (! Schema::hasColumn('settings', 'alerts_enabled')) {
                $t->boolean('alerts_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'alerts_types')) {
                $t->text('alerts_types')->nullable();
            }
        });
        // Off everywhere, only the first time (a company that turns it on later keeps it).
        if (! DB::table('settings')->where('alerts_enabled', 1)->exists()) {
            DB::table('settings')->update(['alerts_enabled' => 0]);
        }
        DB::table('settings')->update(['show_language' => 0]);

        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
