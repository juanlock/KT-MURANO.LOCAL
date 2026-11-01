<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;

/** Side menu (user's request, 2026-11-01): the main dashboard is "Tablero Principal" in Spanish, "Dashboard" in English. */
return new class extends Migration
{
    public function up(): void
    {
        TranslationKeys::ensure('Main_Dashboard', ['en' => 'Dashboard', 'es' => 'Tablero Principal']);
        // ensure() writes the key itself as the English text; the menu shows "Dashboard".
        \Illuminate\Support\Facades\DB::table('translations')->where('key', 'Main_Dashboard')->where('en', 'Main_Dashboard')->update(['en' => 'Dashboard']);
    }

    public function down(): void
    {
    }
};
