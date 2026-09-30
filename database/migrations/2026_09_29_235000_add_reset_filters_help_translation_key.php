<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tooltip of the shared Reset button on list filter rows
 * (resources/src/components/ResetFiltersButton.vue).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('translations')->where('key', 'Reset_Filters_Help')->exists()) {
            return;
        }
        DB::table('translations')->insert([
            'key' => 'Reset_Filters_Help', 'is_default' => 1,
            'en' => 'Clear every filter back to its default',
            'es' => 'Restablecer todos los filtros a su valor predeterminado',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        forget_db_translations();
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Reset_Filters_Help')->delete();
        forget_db_translations();
    }
};
