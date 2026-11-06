<?php

use App\Support\CompanyLogins;
use App\Support\CompanyRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/*
 * Company roles, second pass (user's rules, 2026-11-02):
 *  - every company: "Formularios" -> "Info" (the forms, read only; users kept);
 *    a new standard role "Manager" = the company's Admin Compañía permissions
 *    without configuration (CompanyRoles::syncManager); Ruba's "Manager RG" and
 *    New Era's "ManagerNE" merge into it (users moved, roles removed). Ruba keeps
 *    SupervisorRG / OperatorRG; hospital, school, clinic and restaurant roles stay.
 *  - New Era: info@neweraunisexsalon.com (its Admin Compañía until now) -> Manager;
 *    its only Admin Compañía = a new neweraunisexsalon-admin@kcoltech.com (standard
 *    access password, changed at first sign-in); "StaffNE" -> "Staff" with every
 *    employee login on it (the managers aside).
 *  - Manager logins see every record (users.record_view).
 * Template (999) first so new companies copy the new set. One transaction per
 * company; by name / email, safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        $ids = collect([$tpl])->merge(DB::table('companies')->whereNull('deleted_at')->where('id', '!=', $tpl)->pluck('id'))->map(fn ($i) => (int) $i);
        foreach ($ids as $cid) {
            $log = DB::transaction(fn () => CompanyRoles::normalize($cid));
            if ($log) {
                Log::info("roles 2026-11-02, company $cid: ".implode('; ', $log));
            }
        }

        $newEra = DB::table('users')->where('email', 'info@neweraunisexsalon.com')->whereNull('deleted_at')->value('company_id');
        if ($newEra) {
            DB::transaction(fn () => $this->newEra((int) $newEra));
        }

        // Managers see every record of their company.
        $managerRoles = DB::table('roles')->where('name', CompanyRoles::MANAGER)->whereNull('deleted_at')->pluck('id');
        DB::table('users')->whereIn('id', DB::table('role_user')->whereIn('role_id', $managerRoles)->pluck('user_id'))->update(['record_view' => 1]);
    }

    private function newEra(int $cid): void
    {
        $now = now();
        $roleId = fn (string $name) => DB::table('roles')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->value('id');
        $setRole = function (int $userId, int $rid) {
            DB::table('role_user')->where('user_id', $userId)->delete();
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rid]);
            DB::table('users')->where('id', $userId)->update(['role_id' => $rid]);
        };
        $admin = (int) $roleId(CompanyRoles::ADMIN);
        $manager = (int) $roleId(CompanyRoles::MANAGER);

        // Its only Admin Compañía: the standard access login.
        $email = CompanyLogins::email($cid, 'admin');
        $adminUser = DB::table('users')->where('email', $email)->whereNull('deleted_at')->value('id');
        if (! $adminUser && $admin) {
            $adminUser = DB::table('users')->insertGetId([
                'company_id' => $cid, 'firstname' => 'Admin', 'lastname' => 'New Era',
                'username' => \App\Services\LinkedUserService::uniqueUsername('AdminNewEra'), 'email' => $email,
                'password' => Hash::make(CompanyLogins::ACCESS_PASSWORD), 'phone' => '', 'avatar' => 'no_avatar.png',
                'role_id' => $admin, 'type' => 'admin', 'statut' => 1, 'is_all_warehouses' => 1, 'record_view' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('role_user')->insert(['user_id' => $adminUser, 'role_id' => $admin]);
        }

        // Everyone else who was Admin Compañía (info@...) -> Manager.
        if ($manager && $admin) {
            foreach (DB::table('role_user')->where('role_id', $admin)->where('user_id', '!=', $adminUser)->pluck('user_id') as $uid) {
                $setRole((int) $uid, $manager);
            }
        }

        // StaffNE -> Staff, with every employee login that has no other job role.
        $staff = $roleId('Staff');
        if (! $staff && ($old = $roleId('StaffNE'))) {
            DB::table('roles')->where('id', $old)->update(['name' => 'Staff', 'label' => 'Staff', 'updated_at' => $now]);
            $staff = $old;
        }
        if ($staff) {
            $employeeRole = $roleId('Employee');
            $logins = DB::table('users')->where('company_id', $cid)->where('type', 'employee')->whereNull('deleted_at')->pluck('id');
            foreach ($logins as $uid) {
                $current = DB::table('role_user')->where('user_id', $uid)->value('role_id');
                if (! $current || (int) $current === (int) $employeeRole) {
                    $setRole((int) $uid, (int) $staff);
                }
            }
        }
    }

    public function down(): void
    {
        // Not undone automatically (the production backup taken before the deploy has the old roles).
    }
};
