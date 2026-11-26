<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Formularios -> "Reporte de envíos" is now "Últimos mensajes" (user, 2026-11-26), split into Reclamos and Contáctenos (web).
 * The menu text (key Forms_Report) changes for every company. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        TranslationKeys::ensure('Forms_Report', ['es' => 'Últimos mensajes']);
        DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', ['Forms_Report'])
            ->update(['en' => 'Latest messages', 'es' => 'Últimos mensajes', 'updated_at' => now()]);
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', ['Forms_Report'])
            ->update(['en' => 'Submissions report', 'es' => 'Reporte de envíos', 'updated_at' => now()]);
    }
};
