<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Service -> "Daily Tasks" is now "Tasks" (user's request, 2026-10-06): the
 * page shows Week (default), Day and Month views. Label only -- the route
 * (/service/daily), key and permissions stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('translations')->where('key', 'Daily_Tasks')->update(['en' => 'Tasks', 'es' => 'Tareas', 'updated_at' => now()]);
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Daily_Tasks')->update(['en' => 'Daily Tasks', 'es' => 'Tareas del día', 'updated_at' => now()]);
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }
};
