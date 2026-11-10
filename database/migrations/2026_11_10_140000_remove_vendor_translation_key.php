<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Murano is KcolTech's product (user's rule, 2026-11-10): the original vendor's
 * name goes from every screen and table. The only row that held it is an unused
 * translation key (its Spanish text was also broken). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'key')) {
            DB::table('translations')->where('key', 'stockyVersion')->delete();
        }
    }

    public function down(): void
    {
        // Nothing to put back: the key was not used by any screen.
    }
};
