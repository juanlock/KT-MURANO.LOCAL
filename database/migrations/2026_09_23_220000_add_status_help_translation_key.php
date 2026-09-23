<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tooltip label for the new Status/Payment Status help icon on the Sales
 * and Purchases lists (StatusHelpModal.vue).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('translations')->where('key', 'Status_Help')->exists()) {
            return;
        }
        DB::table('translations')->insert([
            'key' => 'Status_Help',
            'is_default' => 1,
            'en' => 'What do these statuses mean?',
            'es' => '¿Qué significan estos estados?',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Status_Help')->delete();
    }
};
