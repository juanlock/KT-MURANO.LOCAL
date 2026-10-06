<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * (user's request, 2026-10-06)
 *  - New permission register_open_close ("Open / close register"): opening,
 *    closing and cash in / out of a POS register. Every role that has the POS
 *    (Pos_view) gets it -- nothing changes elsewhere -- EXCEPT New Era's
 *    StaffNE: stylists sell on the manager's open register by typing their PIN
 *    on each sale (POS Settings -> "Confirm each sale with the cashier's PIN",
 *    already on), and only the manager opens / closes it.
 *  - New Era: Monica Lock and Silvia Becerra -> ManagerNE (both also work as
 *    stylists, paid by commission); ManagerNE gets Email Office manage too.
 *  - New Era cleanup: the redundant "manager01" admin (already deleted, same
 *    email as AdminNe -- info@neweraunisexsalon.com) -- its leftover employee
 *    record "EmployeeAdmin NE" and its role / location rows are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = fn (string $name) => (int) (DB::table('permissions')->where('name', $name)->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => $name, 'label' => $name, 'description' => $name, 'created_at' => now(), 'updated_at' => now()]));
        $grant = function (int $roleId, int $pid) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
            }
        };

        $openClose = $permId('register_open_close');
        $staffNe = DB::table('roles')->where('company_id', 3)->where('name', 'StaffNE')->whereNull('deleted_at')->value('id');
        foreach (DB::table('permission_role')->where('permission_id', $permId('Pos_view'))->pluck('role_id') as $roleId) {
            if ((int) $roleId !== (int) $staffNe) {
                $grant((int) $roleId, $openClose);
            }
        }
        if (! DB::table('translations')->where('key', 'Register_Open_Close')->exists()) {
            DB::table('translations')->insert(['key' => 'Register_Open_Close', 'is_default' => 1, 'en' => 'Open / close register', 'es' => 'Abrir / cerrar caja', 'created_at' => now(), 'updated_at' => now()]);
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }

        if (! DB::table('companies')->where('id', 3)->exists()) {
            return;
        }
        $manager = DB::table('roles')->where('company_id', 3)->where('name', 'ManagerNE')->whereNull('deleted_at')->value('id');
        if ($manager) {
            $grant((int) $manager, $openClose);
            $grant((int) $manager, $permId('email_office_manage'));
            foreach (DB::table('users')->where('company_id', 3)->whereIn('username', ['monicalock', 'silvia'])->pluck('id') as $uid) {
                DB::table('role_user')->where('user_id', $uid)->delete();
                DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $manager, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('users')->where('id', $uid)->update(['role_id' => $manager]);
            }
        }

        // manager01: the user is already deleted; its leftovers go.
        $old = DB::table('users')->where('company_id', 3)->where('username', 'manager01')->first(['id', 'deleted_at']);
        if ($old) {
            DB::table('users')->where('id', $old->id)->update(['statut' => 0, 'deleted_at' => $old->deleted_at ?: now()]);
            DB::table('role_user')->where('user_id', $old->id)->delete();
            DB::table('user_warehouse')->where('user_id', $old->id)->delete();
            DB::table('employees')->where('user_id', $old->id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
