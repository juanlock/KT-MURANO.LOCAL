<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo: RRHH is its own top-level group of the menu (the user, 2026-11-28: "mueve ese módulo de back office a
 * RRHH"), right after Flota y Logística, out of Back Office y Administración -> Organización. Operaciones did not see it
 * inside Back Office (menu_hide:custom_tc_backoffice), so it gets menu_hide:hrm and keeps not seeing it; Director,
 * Administración (Manager) and IT Admin see it. By names. Safe to run twice; down() puts it back in Organización.
 */
return new class extends Migration
{
    private function company(): int
    {
        return (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
    }

    public function up(): void
    {
        $cid = $this->company();
        if (! $cid) {
            return;
        }
        $now = now();
        $top = fn () => DB::table('menu_positions')->where('company_id', $cid)->whereNull('parent_key');
        $hrm = DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'hrm')->first();
        if ($hrm && $hrm->parent_key !== null) {
            $after = $top()->where('item_key', 'custom_tc_flota')->value('sort_order');
            $sort = $after !== null ? (int) $after + 1 : (int) $top()->max('sort_order') + 1;
            $top()->where('sort_order', '>=', $sort)->increment('sort_order');
            DB::table('menu_positions')->where('id', $hrm->id)->update(['parent_key' => null, 'sort_order' => $sort,
                'label' => 'RRHH', 'hidden' => 0, 'updated_at' => $now]);
        }

        $perm = 'menu_hide:hrm';
        if (! DB::table('permissions')->where('name', $perm)->exists()) {
            DB::table('permissions')->insert(['name' => $perm, 'label' => $perm, 'created_at' => $now, 'updated_at' => $now]);
        }
        $pid = DB::table('permissions')->where('name', $perm)->value('id');
        foreach (DB::table('roles')->where('company_id', $cid)->where('name', 'Operaciones')->whereNull('deleted_at')->pluck('id') as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
    }

    public function down(): void
    {
        $cid = $this->company();
        if (! $cid) {
            return;
        }
        DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'hrm')
            ->update(['parent_key' => 'custom_company', 'sort_order' => 0, 'label' => null, 'icon' => null]);
        $roles = DB::table('roles')->where('company_id', $cid)->where('name', 'Operaciones')->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $roles)
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'menu_hide:hrm')->pluck('id'))->delete();
    }
};
