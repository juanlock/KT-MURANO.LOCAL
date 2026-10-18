<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Names the SuperAdmin gave menu items in Manage Menu ("Main Modules",
 * "Access Management"...) are stored as plain English text; the side menu now
 * shows them in the active language when a translation exists (the text with
 * underscores as the key). These are the ones that had none -- Spanish shown
 * for Vanclem / Ruba, English stays as typed. Only keys that don't exist yet.
 */
return new class extends Migration
{
    private const ROWS = [
        'Access_Management' => ['Access Management', 'Gestión de accesos'],
        'E-Wallet_Management' => ['E-Wallet Management', 'Gestión de monedero electrónico'],
        'Employee_Contracts' => ['Employee Contracts', 'Contratos de empleados'],
        'Home_Buttons' => ['Home Buttons', 'Botones de inicio'],
        'Home_sections' => ['Home sections', 'Secciones de inicio'],
        'Kitchen_Management' => ['Kitchen Management', 'Gestión de cocina'],
        'Location_Report' => ['Location Report', 'Informe de ubicaciones'],
        'Main_Modules' => ['Main Modules', 'Módulos principales'],
        'Manage_Menu' => ['Manage Menu', 'Administrar menú'],
        'Organization' => ['Organization', 'Organización'],
        'POS_Launcher' => ['POS Launcher', 'Abrir POS'],
        'POS_Management' => ['POS Management', 'Gestión de POS'],
        'Sales_Contracts' => ['Sales Contracts', 'Contratos de venta'],
        'Store_Management' => ['Store Management', 'Gestión de tienda'],
        'Serials' => ['Serials', 'Números de serie'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ROWS as $key => [$en, $es]) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('translations')->insert([
                'key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        forget_db_translations();
    }

    public function down(): void
    {
        DB::table('translations')->whereIn('key', array_keys(self::ROWS))->delete();
        forget_db_translations();
    }
};
