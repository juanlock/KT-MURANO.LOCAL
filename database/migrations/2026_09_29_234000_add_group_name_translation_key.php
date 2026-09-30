<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales List: "Group Name" filter and column (product group of the sale's lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('translations')->where('key', 'Group_Name')->exists()) {
            return;
        }
        DB::table('translations')->insert([
            'key' => 'Group_Name', 'is_default' => 1,
            'en' => 'Group Name', 'es' => 'Nombre del grupo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        forget_db_translations();
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Group_Name')->delete();
        forget_db_translations();
    }
};
