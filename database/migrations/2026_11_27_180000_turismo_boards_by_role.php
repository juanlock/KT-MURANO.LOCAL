<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo's boards by role (the user, 2026-11-27): Director and IT Admin see every board (no list); Administración
 * (role Manager: Mayte) only the Administración group; Operaciones (Daniel) only the Operaciones group, opening on
 * Programación. The server refuses a board's data to a role without it (middleware dashtab). Safe to run twice.
 */
return new class extends Migration
{
    private const LISTS = [
        'Manager' => ['transport_daysales', 'transport_pointsales', 'transport_cashbook', 'transport_customers', 'transport_expenses'],
        'Operaciones' => ['transport_programming', 'transport_production', 'transport_fleet', 'transport_departures', 'transport',
            'transport_aura_parcels', 'transport_claims', 'transport_monitor'],
    ];

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        foreach (self::LISTS as $role => $tabs) {
            DB::table('roles')->where('company_id', $cid)->where('name', $role)->whereNull('deleted_at')
                ->update(['dashboard_tabs' => json_encode($tabs), 'updated_at' => now()]);
        }
        // Director and IT Admin: every board.
        DB::table('roles')->where('company_id', $cid)->whereIn('name', ['Director', 'IT Admin'])->whereNull('deleted_at')
            ->update(['dashboard_tabs' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
    }
};
