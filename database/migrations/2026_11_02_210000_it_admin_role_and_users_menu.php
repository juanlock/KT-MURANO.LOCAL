<?php

use App\Support\CompanyRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * User's requests, 2026-11-02:
 *  - the role "Admin Compañía" is called "IT Admin" in every company (AdminSystem's
 *    template included); same role, same permissions and users -- only the name;
 *  - the menu "Los usuarios" is "Usuarios" in Spanish (translation key Users).
 * By name, safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (CompanyRoles::OLD_ADMIN_NAMES as $old) {
            foreach (DB::table('roles')->where('name', $old)->whereNull('deleted_at')->get(['id', 'company_id', 'label']) as $r) {
                // A company that somehow already has an IT Admin: its users move there, the old one goes.
                $it = DB::table('roles')->where('company_id', $r->company_id)->where('name', CompanyRoles::ADMIN)->whereNull('deleted_at')->value('id');
                if ($it) {
                    foreach (DB::table('role_user')->where('role_id', $r->id)->pluck('user_id') as $uid) {
                        DB::table('role_user')->updateOrInsert(['user_id' => $uid, 'role_id' => $it]);
                    }
                    DB::table('role_user')->where('role_id', $r->id)->delete();
                    DB::table('users')->where('role_id', $r->id)->update(['role_id' => $it]);
                    DB::table('roles')->where('id', $r->id)->update(['deleted_at' => now()]);
                    continue;
                }
                DB::table('roles')->where('id', $r->id)->update([
                    'name' => CompanyRoles::ADMIN,
                    'label' => in_array($r->label, [$old, null, ''], true) ? CompanyRoles::ADMIN : $r->label,
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', ['Users'])->where('es', 'Los usuarios')
            ->update(['es' => 'Usuarios', 'updated_at' => now()]);
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
