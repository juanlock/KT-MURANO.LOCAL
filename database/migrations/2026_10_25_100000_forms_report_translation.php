<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Forms -> "Submissions report" menu label (English / Spanish), by key, only when missing. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translations') && ! DB::table('translations')->where('key', 'Forms_Report')->exists()) {
            DB::table('translations')->insert(['key' => 'Forms_Report', 'is_default' => 1, 'en' => 'Submissions report', 'es' => 'Reporte de envíos', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // kept
    }
};
