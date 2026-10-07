<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SuperAdmin role ("Owner") was created before the Portfolio module, so it
 * never got portfolio_manage and SuperAdmins saw no Portfolio menu. A SuperAdmin
 * has every permission: grant it to the roles of every SuperAdmin user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permission_role')) {
            return;
        }
        $pid = DB::table('permissions')->where('name', 'portfolio_manage')->value('id');
        if (! $pid) {
            return;
        }
        // The role that counts is role_user's (users.role_id too, for older rows).
        $superIds = DB::table('users')->whereRaw('LOWER(type) = ?', ['superadmin'])->pluck('id');
        $roles = DB::table('role_user')->whereIn('user_id', $superIds)->pluck('role_id')
            ->merge(DB::table('users')->whereIn('id', $superIds)->whereNotNull('role_id')->pluck('role_id'))
            ->unique();
        foreach ($roles as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
        // And every other permission it lacks (SuperAdmin = owner, all modules).
        \App\Support\SuperAdminAccess::sync();
    }

    public function down(): void
    {
        // keep the grant
    }
};
