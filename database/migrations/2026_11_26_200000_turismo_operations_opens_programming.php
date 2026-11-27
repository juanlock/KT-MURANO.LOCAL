<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo's Operaciones role opens its dashboard on Programación (the user, 2026-11-26): a role with its own tab
 * list opens on the first one of it, so transport_programming goes first in that list. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        $role = $cid ? DB::table('roles')->where('company_id', $cid)->where('name', 'Operaciones')->whereNull('deleted_at')->first() : null;
        if (! $role) {
            return;
        }
        $tabs = json_decode((string) $role->dashboard_tabs, true) ?: [];
        if (! $tabs || ($tabs[0] ?? null) === 'transport_programming') {
            return;
        }
        $tabs = array_values(array_merge(['transport_programming'], array_diff($tabs, ['transport_programming'])));
        DB::table('roles')->where('id', $role->id)->update(['dashboard_tabs' => json_encode($tabs), 'updated_at' => now()]);
    }

    public function down(): void
    {
    }
};
