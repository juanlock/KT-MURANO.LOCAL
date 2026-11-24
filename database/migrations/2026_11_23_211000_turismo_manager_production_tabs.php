<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turismo Cautivo (user, 2026-11-23: "para Mayte adecua los tableros para que se muestren como producción por bus y reporte
 * del día de ventas"): the Manager's dashboard (Mayte) opens on "Ventas del día", then "Producción por bus", then the tabs
 * it already had. Only adds; the rest of the role's list (changed by the user in production) is kept. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        $role = $cid ? DB::table('roles')->where('company_id', $cid)->where('name', 'Manager')->whereNull('deleted_at')->first() : null;
        if (! $role) {
            return;
        }
        $tabs = json_decode((string) $role->dashboard_tabs, true) ?: [];
        if (! $tabs) {
            return; // no list = every tab the role's permissions allow (the new ones included)
        }
        $tabs = array_values(array_unique(array_merge(['transport_daysales', 'transport_production'], $tabs)));
        DB::table('roles')->where('id', $role->id)->update(['dashboard_tabs' => json_encode($tabs), 'updated_at' => now()]);
    }

    public function down(): void
    {
    }
};
