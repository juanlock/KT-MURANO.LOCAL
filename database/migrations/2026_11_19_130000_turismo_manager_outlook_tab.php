<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turismo Cautivo (user's rule, 2026-11-19): the projected profit of the "Mando operativo" is for Julio (Director) and Mayte
 * (Manager) only -- Operaciones keeps the board without amounts (TransportOutlook::withoutMoney). Mayte's dashboard gets the
 * Mando operativo tab, after the departures report. Safe to run twice; by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $role = DB::table('roles')->where('company_id', $cid)->where('name', 'Manager')->whereNull('deleted_at')->first();
        if (! $role) {
            return;
        }
        $tabs = json_decode((string) $role->dashboard_tabs, true) ?: [];
        if ($tabs && ! in_array('transport_outlook', $tabs, true)) {
            array_splice($tabs, (int) array_search('transport_departures', $tabs, true) + 1, 0, ['transport_outlook']);
            DB::table('roles')->where('id', $role->id)->update(['dashboard_tabs' => json_encode(array_values($tabs)), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
