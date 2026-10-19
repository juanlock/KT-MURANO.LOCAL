<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login page in Spanish (user's rule, 2026-10-19: Murano opens in the
 * computer's language from the login page on). The Login Page Settings texts
 * are per-language JSON maps ({"en": "...", "es": "..."}) and the standard
 * ones only had English, so a Spanish computer still saw "Welcome back",
 * "Sign in"... Adds the Spanish of the STANDARD texts only: a field gets "es"
 * when it has none and its English is exactly the standard text -- a
 * company's own texts (e.g. Ruba's) are never touched. Safe to run twice.
 */
return new class extends Migration
{
    private const ES = [
        'Manage your business smarter.' => 'Administre su negocio de forma más inteligente.',
        'Streamline inventory, track sales, and grow your business — all from one powerful dashboard.' => 'Simplifique su inventario, controle sus ventas y haga crecer su negocio, todo desde un solo panel.',
        'Welcome back' => 'Bienvenido de nuevo',
        'Sign in to your account to continue' => 'Ingrese a su cuenta para continuar',
        'Secure & Reliable' => 'Seguro y confiable',
        'Real-time inventory tracking' => 'Inventario en tiempo real',
        'Multi-location POS support' => 'Punto de venta en varias sedes',
        'Advanced reporting & analytics' => 'Informes y análisis avanzados',
        'Sign in' => 'Ingresar',
    ];

    private const FIELDS = ['login_hero_title', 'login_hero_subtitle', 'login_panel_title', 'login_panel_subtitle', 'login_hero_badge',
        'login_hero_feature_1', 'login_hero_feature_2', 'login_hero_feature_3', 'login_btn_text'];

    public function up(): void
    {
        $fields = array_values(array_filter(self::FIELDS, fn ($f) => Schema::hasColumn('settings', $f)));
        if (! $fields) {
            return;
        }
        foreach (DB::table('settings')->get(array_merge(['id'], $fields)) as $row) {
            $update = [];
            foreach ($fields as $f) {
                $map = json_decode((string) $row->$f, true);
                if (! is_array($map) || ! empty($map['es'])) {
                    continue;
                }
                $en = trim((string) ($map['en'] ?? ''));
                if (isset(self::ES[$en])) {
                    $map['es'] = self::ES[$en];
                    $update[$f] = json_encode($map, JSON_UNESCAPED_UNICODE);
                }
            }
            if ($update) {
                DB::table('settings')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        // Leaves the Spanish texts (removing them would also remove any an admin edited since).
    }
};
