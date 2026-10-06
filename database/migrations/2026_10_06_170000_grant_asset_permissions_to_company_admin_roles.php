<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Assets on for every company (2026-10-06): the roles the companies' admins
 * actually use (any role held by an `admin` user) get the 5 Assets
 * permissions, so the menu shows for them -- the module flag alone isn't
 * enough (the role is mandatory). Employee / operator / customer roles untouched.
 */
return new class extends Migration
{
    private const PERMS = ['assets', 'asset_assignments', 'asset_maintenance', 'asset_transfers', 'asset_reports'];

    public function up(): void
    {
        $permIds = DB::table('permissions')->whereIn('name', self::PERMS)->pluck('id');
        $roleIds = DB::table('role_user as ru')->join('users as u', 'u.id', '=', 'ru.user_id')->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->whereIn('u.type', ['admin', 'Admin'])->whereNull('r.deleted_at')->distinct()->pluck('ru.role_id');
        foreach ($roleIds as $roleId) {
            foreach ($permIds as $pid) {
                if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        // Not reversed: can't tell grants made here from ones made by hand.
    }
};
