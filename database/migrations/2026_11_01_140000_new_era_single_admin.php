<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New Era (user's decision, 2026-11-01): one Admin Compañía only --
 * info@neweraunisexsalon.com (it had AdminNE); admin@neweraunisexsalon.com
 * (it had the limited "Admin") goes to ManagerNE. By email / role name, safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $user = DB::table('users')->where('email', 'admin@neweraunisexsalon.com')->whereNull('deleted_at')->first(['id', 'company_id']);
        if (! $user) {
            return;
        }
        $role = DB::table('roles')->where('company_id', $user->company_id)->where('name', 'ManagerNE')->whereNull('deleted_at')->value('id');
        if (! $role) {
            return;
        }
        DB::transaction(function () use ($user, $role) {
            DB::table('role_user')->where('user_id', $user->id)->delete();
            DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $role]);
            DB::table('users')->where('id', $user->id)->update(['role_id' => $role]);
        });
    }

    public function down(): void
    {
    }
};
