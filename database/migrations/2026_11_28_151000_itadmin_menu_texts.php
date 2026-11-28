<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* IT Admin's menu labels (2026-11-28): English and Spanish. Safe to run twice. */
return new class extends Migration
{
    private const TEXTS = [
        'IT_Menu_Networks' => ['Networks', 'Redes'],
        'IT_Assets_All' => ['All assets', 'Todos los equipos'],
        'IT_Cat_firewalls' => ['Firewalls', 'Firewalls'],
        'IT_Cat_network' => ['Network devices', 'Equipos de red'],
        'IT_Cat_servers' => ['Servers', 'Servidores'],
        'IT_Cat_computers' => ['Computers', 'Computadoras'],
        'IT_Cat_printers' => ['Printers', 'Impresoras'],
        'IT_Cat_phones' => ['Phones', 'Teléfonos'],
        'IT_Cat_mobiles' => ['Mobiles', 'Móviles'],
        'IT_Cat_devices' => ['Devices', 'Otros equipos'],
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
