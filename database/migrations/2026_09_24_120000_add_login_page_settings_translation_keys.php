<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New SuperAdmin-only Login Page Settings page (split out of Appearance
 * Settings -- see SettingsController@get/update_login_page_settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'Login_Page_Settings' => [
                'en' => 'Login Page Settings',
                'es' => 'Configuración de la Página de Inicio de Sesión',
            ],
            'SuperAdmin_Only' => [
                'en' => 'SuperAdmin only',
                'es' => 'Solo SuperAdmin',
            ],
        ];

        foreach ($rows as $key => $translations) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('translations')->insert([
                'key' => $key,
                'is_default' => 1,
                'en' => $translations['en'],
                'es' => $translations['es'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('translations')->whereIn('key', ['Login_Page_Settings', 'SuperAdmin_Only'])->delete();
    }
};
