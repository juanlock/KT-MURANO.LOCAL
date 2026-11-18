<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Quotations" said "Citas" (appointments) in Spanish, so the Sales menu showed "Citas" next to
 * "Cotizaciones" (user, 2026-11-17, Alo Mundo). Spanish = "Cotizaciones". By key, safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            DB::table('translations')->where('key', 'Quotations')->where('es', 'Citas')->update(['es' => 'Cotizaciones', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The old value was a mistranslation; nothing to put back.
    }
};
