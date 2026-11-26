<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo (user's rule, 2026-11-26): the menus that configure the system -- Configuración, Back Office y
 * Administración (companies, employees...) -- only for the IT Admin; Manager, Director and Operaciones see only Turismo's
 * modules and their boards. Done per role with menu_hide:<group key> (lib/menuVisibility.js), so the IT Admin keeps them.
 * Safe to run twice; by name.
 */
return new class extends Migration
{
    private const ROLES = ['Manager', 'Director', 'Operaciones'];

    private const HIDE = ['menu_hide:custom_tc_backoffice', 'menu_hide:settings'];

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $roles = DB::table('roles')->where('company_id', $cid)->whereIn('name', self::ROLES)->whereNull('deleted_at')->pluck('id');
        foreach (self::HIDE as $perm) {
            if (! DB::table('permissions')->where('name', $perm)->exists()) {
                DB::table('permissions')->insert(['name' => $perm, 'label' => $perm, 'created_at' => now(), 'updated_at' => now()]);
            }
            $pid = DB::table('permissions')->where('name', $perm)->value('id');
            foreach ($roles as $rid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        $roles = DB::table('roles')->where('company_id', $cid)->whereIn('name', self::ROLES)->pluck('id');
        $pids = DB::table('permissions')->whereIn('name', self::HIDE)->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $roles)->whereIn('permission_id', $pids)->delete();
    }
};
