<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo shows only what it uses (user's rule, 2026-11-26, again: "este módulo de transporte tiene su propio flujo;
 * los módulos estándar como ventas se ocultan, como en New Era y Hordex... reducir a los menús que usa el cliente ahorita;
 * si hace falta un módulo o form te lo pido"). Tickets and parcels are sold in Jelaf / AuraSoft, so in Back Office y
 * Administración the company's menu hides Ventas, Compras, Proveedores and Informes (incl. Informes de IA); Organización keeps
 * only RRHH -> Empleados (the pilots and staff, user 2026-11-23). Hidden for every login of the company, not deleted: Manage
 * Menu shows them again. Also makes sure Programación de buses sits in Flota y Logística after Salidas diarias. Safe to run twice.
 */
return new class extends Migration
{
    private const HIDE = [
        'custom_tc_backoffice' => ['sales', 'purchases', 'reports', 'custom_suppliers'],
        'custom_company' => ['/app/hrm/company', '/app/hrm/departments', '/app/reports/warehouse_report'],
        'hrm' => ['/app/hrm/company', '/app/hrm/departments', '/app/hrm/designations', '/app/hrm/office_Shift', '/app/hrm/attendance',
            '/app/hrm/leaves/list', '/app/hrm/leaves/type', '/app/hrm/holidays', '/app/hrm/payrolls', '/app/contracts', '/app/reports/attendance_report'],
    ];

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $now = now();
        foreach (self::HIDE as $parent => $items) {
            foreach ($items as $i => $item) {
                // One position per company and screen (unique company_id + item_key), whatever its group.
                $q = DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $item);
                if ($q->exists()) {
                    $q->update(['hidden' => 1, 'updated_at' => $now]);
                } else {
                    DB::table('menu_positions')->insert(['company_id' => $cid, 'item_key' => $item, 'parent_key' => $parent, 'sort_order' => 50 + $i,
                        'external' => 0, 'is_custom' => 0, 'hidden' => 1, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        // RRHH -> Empleados stays visible.
        $emp = DB::table('menu_positions')->where('company_id', $cid)->where('item_key', '/app/hrm/employees');
        $emp->exists() ? $emp->update(['hidden' => 0, 'updated_at' => $now])
            : DB::table('menu_positions')->insert(['company_id' => $cid, 'item_key' => '/app/hrm/employees', 'parent_key' => 'hrm', 'sort_order' => 1,
                'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now]);
        // Programación de buses in Flota y Logística, after Salidas diarias.
        $flota = fn () => DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'custom_tc_flota');
        if (! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', '/app/transport/schedule')->exists()) {
            $daily = $flota()->where('item_key', '/app/transport/daily')->value('sort_order');
            $sort = $daily !== null ? (int) $daily + 1 : (int) $flota()->max('sort_order') + 1;
            $flota()->where('sort_order', '>=', $sort)->increment('sort_order');
            DB::table('menu_positions')->insert(['company_id' => $cid, 'item_key' => '/app/transport/schedule', 'parent_key' => 'custom_tc_flota',
                'sort_order' => $sort, 'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        foreach (self::HIDE as $parent => $items) {
            DB::table('menu_positions')->where('company_id', $cid)->whereIn('item_key', $items)->update(['hidden' => 0]);
        }
    }
};
