<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login page texts of the KCOLTECH design (user's request, 2026-10-25: word for
 * word as the mockup KT-TOOLS/files/login/KCOLTECH SaaS Dashboard Login
 * Showcase.png), English + Spanish. Like 2026_10_19_130000: only a field still
 * holding the STANDARD text is replaced -- a company's own texts (Login Page
 * Settings) are never touched. Also the login's own placeholders / link
 * (translations Login_*). Safe to run twice.
 */
return new class extends Migration
{
    /** field => [old standard English, new English, new Spanish] */
    private const TEXTS = [
        'login_hero_subtitle' => ['Streamline inventory, track sales, and grow your business — all from one powerful dashboard.',
            'Streamline your operations with Murano, the all-in-one business management system. Keep your inventory in check, manage multiple locations and get real-time insights with powerful reporting and analytics.',
            'Simplifique sus operaciones con Murano, el sistema de gestión empresarial todo en uno. Controle su inventario, administre varias sucursales y obtenga información en tiempo real con reportes y análisis potentes.'],
        'login_hero_feature_1' => ['Real-time inventory tracking', 'Inventory Tracking', 'Control de inventario'],
        'login_hero_feature_2' => ['Multi-location POS support', 'Multi-location POS', 'POS multisucursal'],
        'login_hero_feature_3' => ['Advanced reporting & analytics', 'Reporting & Analytics', 'Reportes y análisis'],
        'login_panel_subtitle' => ['Sign in to your account to continue', 'Sign in to your account to continue.', 'Ingrese a su cuenta para continuar.'],
        'login_btn_text' => ['Sign in', 'Sign In', 'Ingresar'],
    ];

    private const TRANSLATIONS = [
        'Login_email_placeholder' => ['Email address', 'Correo electrónico'],
        'Login_password_placeholder' => ['Password', 'Contraseña'],
        'Login_forgot_password' => ['Forgot your password?', '¿Olvidó su contraseña?'],
    ];

    public function up(): void
    {
        $fields = array_values(array_filter(array_keys(self::TEXTS), fn ($f) => Schema::hasColumn('settings', $f)));
        foreach (DB::table('settings')->get(array_merge(['id'], $fields)) as $row) {
            $update = [];
            foreach ($fields as $f) {
                [$old, $en, $es] = self::TEXTS[$f];
                $map = json_decode((string) $row->$f, true);
                $cur = trim((string) (is_array($map) ? ($map['en'] ?? '') : $row->$f));
                // Only the standard text (an empty feature may be deliberate: the page hides it).
                if ($cur === $old) {
                    $update[$f] = json_encode(['en' => $en, 'es' => $es], JSON_UNESCAPED_UNICODE);
                }
            }
            if ($update) {
                DB::table('settings')->where('id', $row->id)->update($update);
            }
        }

        if (Schema::hasTable('translations')) {
            foreach (self::TRANSLATIONS as $key => [$en, $es]) {
                if (! DB::table('translations')->where('key', $key)->exists()) {
                    DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach (['en', 'es'] as $loc) {
                Cache::forget('db_translations.'.$loc);
            }
        }
    }

    public function down(): void
    {
        // Leaves the texts (an admin may have edited them since).
    }
};
