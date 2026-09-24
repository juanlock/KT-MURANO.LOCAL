<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DataTable.vue's Columns picker popover -- makes the (already-persistent)
 * hide/unhide choice visible/discoverable, plus its "Reset" link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('translations')->where('key', 'Columns_Remember_Hint')->exists()) {
            return;
        }
        DB::table('translations')->insert([
            'key' => 'Columns_Remember_Hint',
            'is_default' => 1,
            'en' => 'Your choices are kept for next time.',
            'es' => 'Tus cambios se guardan para la próxima vez.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Columns_Remember_Hint')->delete();
    }
};
