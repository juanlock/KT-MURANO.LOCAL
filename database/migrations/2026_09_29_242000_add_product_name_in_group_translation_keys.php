<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Product form -> Basic Information: Type / Group Name / Name order; the Name
 * box suggests the names already in the chosen group (helper texts).
 */
return new class extends Migration
{
    private array $keys = [
        'Name_In_Group_Help' => [
            'Choose the Group Name first — the names already in that group are listed. Pick one as a starting point or type a new name.',
            'Elija primero el Nombre del grupo — se listan los nombres que ya tiene ese grupo. Tome uno como base o escriba un nombre nuevo.',
        ],
        'Name_Exists_In_Group' => [
            'This name already exists in this group — change it if this is a different product.',
            'Este nombre ya existe en este grupo — cámbielo si es un producto distinto.',
        ],
        'Name_Pick_Or_Type' => ['Pick a name from the group or type a new one', 'Elija un nombre del grupo o escriba uno nuevo'],
        'Name_Choose_Group_First' => ['Choose the Group Name first', 'Elija primero el Nombre del grupo'],
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
        DB::table('translations')->whereIn('key', array_keys($this->keys))->delete();
        forget_db_translations();
    }
};
