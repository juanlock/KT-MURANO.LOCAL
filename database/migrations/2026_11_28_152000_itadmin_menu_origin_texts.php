<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* IT Admin's menu with HTD-IT's structure (2026-11-28, "ponlo similar a la del origen"): its labels in English and Spanish. Safe to run twice. */
return new class extends Migration
{
    private const TEXTS = [
        'IT_Dashboard' => ['IT Dashboard', 'IT Dashboard'],
        'IT_KnowledgeBase' => ['Knowledge Base', 'Base de conocimiento'],
        'IT_Applications' => ['Applications', 'Aplicaciones'],
        'IT_NetworkMap' => ['Networks map', 'Mapa de redes'],
        'IT_Net_1_Internet' => ['1 · Internet Access', '1 · Acceso a Internet'],
        'IT_Net_2_Telephony' => ['2 · Telephony', '2 · Telefonía'],
        'IT_Net_3_Systems' => ['3 · Network & Systems', '3 · Red y Sistemas'],
        'IT_Net_4_Services' => ['4 · Services / Providers', '4 · Servicios / Proveedores'],
        'IT_ISP' => ['ISP Connections', 'Conexiones ISP'],
        'IT_VPN' => ['VPN tunnels', 'Túneles VPN'],
        'IT_TelephonyServices' => ['Telephony services', 'Servicios de telefonía'],
        'IT_HomeNetworks' => ['Home Networks', 'Redes de casa'],
        'IT_SystemsServices' => ['Network & Systems services', 'Servicios de red y sistemas'],
        'IT_Rooms' => ['Rooms', 'Salas'],
        'IT_Gallery' => ['Gallery', 'Galería'],
        'IT_BuildingServices' => ['Services / Providers', 'Servicios / Proveedores'],
        'IT_AllServices' => ['IT Services (all)', 'Servicios IT (todos)'],
        'IT_Purchases' => ['Purchases', 'Compras'],
        'IT_Providers' => ['Providers', 'Proveedores'],
        'IT_Licenses' => ['Licenses', 'Licencias'],
        'IT_Passwords' => ['Passwords', 'Contraseñas'],
        'IT_Assets' => ['Assets', 'Equipos'],
    ];

    public function up(): void
    {
        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
