<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Products list: "Type Product" filter (Products / Services / All).
 */
return new class extends Migration
{
    private array $keys = [
        'Type_Product' => ['Type Product', 'Tipo de producto'],
        'Services' => ['Services', 'Servicios'],
    ];

    public function up(): void
    {
        foreach ($this->keys as $key => [$en, $es]) {
            if (! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert([
                    'key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        forget_db_translations();
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Type_Product')->delete();
        forget_db_translations();
    }
};
