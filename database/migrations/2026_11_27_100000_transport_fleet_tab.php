<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Dashboard board "Flota" (user's request, 2026-11-27, group Operaciones): the buses table that was under Programación, with
 * the fleet's key figures on top. Every Turismo role whose tab list has Programación gets Flota right after it. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        foreach ($cid ? DB::table('roles')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('dashboard_tabs')->get() : [] as $role) {
            $tabs = json_decode((string) $role->dashboard_tabs, true) ?: [];
            $at = array_search('transport_programming', $tabs, true);
            if ($at === false || in_array('transport_fleet', $tabs, true)) {
                continue;
            }
            array_splice($tabs, $at + 1, 0, ['transport_fleet']);
            DB::table('roles')->where('id', $role->id)->update(['dashboard_tabs' => json_encode(array_values($tabs)), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
