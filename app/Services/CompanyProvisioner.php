<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Company;
use App\Models\PosSetting;
use App\Models\role_user;
use App\Models\Setting;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a ready-to-use company (HRM -> Companies -> Add), in one
 * transaction, so a new tenant works on first login:
 *
 *  - the company row
 *  - its own copies of the template company's roles (Owner, Admin,
 *    Admin-ALL, Employee, Customer, Sales Agent) with their permissions --
 *    roles are per company (2026_09_23_110000_add_company_id_to_roles_table)
 *  - its Setting / PosSetting / StoreSetting rows (cloned from the template)
 *    named after the company
 *  - a main warehouse (set as the Default Warehouse)
 *  - a Walk-in Customer (set as the Default Customer)
 *  - HR basics: an office shift, an "Administration" department and an
 *    "Administrator" designation (employees need all three)
 *  - the company's admin user (type admin, all warehouses) + its employee
 *
 * Demo data is NOT generated here -- the caller runs DemoRunner afterwards,
 * outside this transaction, so a demo failure never undoes the company.
 */
class CompanyProvisioner
{
    /**
     * @param  array  $company  name, email?, phone?, country?
     * @param  array  $admin    firstname, lastname?, email, password, role? (role name)
     * @return array{company: Company, user: User, employee_id: int}
     */
    public static function create(array $company, array $admin): array
    {
        return DB::transaction(function () use ($company, $admin) {
            $now = now();

            $co = Company::create([
                'name' => $company['name'],
                'email' => $company['email'] ?? null,
                'phone' => $company['phone'] ?? null,
                'country' => $company['country'] ?? null,
            ]);
            $cid = (int) $co->id;

            // Roles: copy the template company's, permissions included.
            $roleMap = [];
            $templateRoles = DB::table('roles')->where('company_id', Setting::TEMPLATE_COMPANY_ID)->whereNull('deleted_at')->get();
            foreach ($templateRoles as $tr) {
                $newId = DB::table('roles')->insertGetId([
                    'company_id' => $cid, 'name' => $tr->name, 'label' => $tr->label,
                    'description' => $tr->description, 'status' => $tr->status,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $roleMap[$tr->name] = $newId;
                $perms = DB::table('permission_role')->where('role_id', $tr->id)->pluck('permission_id');
                if ($perms->isNotEmpty()) {
                    DB::table('permission_role')->insert($perms->map(fn ($p) => ['permission_id' => $p, 'role_id' => $newId])->all());
                }
            }

            // Warehouse.
            $warehouseId = DB::table('warehouses')->insertGetId([
                'company_id' => $cid, 'name' => 'Main Warehouse',
                'email' => $company['email'] ?? null, 'mobile' => $company['phone'] ?? null,
                'country' => $company['country'] ?? null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // Walk-in customer (the Default Customer).
            $walkIn = Client::create([
                'company_id' => $cid, 'name' => 'Walk-in Customer',
                'code' => ((int) DB::table('clients')->max('code')) + 1,
            ]);

            // Settings rows, cloned from the template, then made this company's.
            $setting = Setting::forCompany($cid);
            $setting->update([
                'CompanyName' => $company['name'],
                'email' => $company['email'] ?? $setting->email,
                'CompanyPhone' => $company['phone'] ?? $setting->CompanyPhone,
                'warehouse_id' => $warehouseId,
                'client_id' => $walkIn->id,
            ]);
            PosSetting::forCompany($cid);
            StoreSetting::forCompany($cid);

            // HR basics every employee needs.
            $shiftId = DB::table('office_shifts')->insertGetId([
                'company_id' => $cid, 'name' => 'Regular Hours 9:00-17:00',
                'monday_in' => '09:00AM', 'monday_out' => '17:00PM',
                'tuesday_in' => '09:00AM', 'tuesday_out' => '17:00PM',
                'wednesday_in' => '09:00AM', 'wednesday_out' => '17:00PM',
                'thursday_in' => '09:00AM', 'thursday_out' => '17:00PM',
                'friday_in' => '09:00AM', 'friday_out' => '17:00PM',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $departmentId = DB::table('departments')->insertGetId([
                'company_id' => $cid, 'department' => 'Administration', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $designationId = DB::table('designations')->insertGetId([
                'company_id' => $cid, 'department_id' => $departmentId, 'designation' => 'Administrator',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // The company's admin user.
            $roleName = $admin['role'] ?? 'Admin-ALL';
            $roleId = $roleMap[$roleName] ?? $roleMap['Admin-ALL'] ?? $roleMap['Admin'] ?? reset($roleMap);
            $firstname = trim($admin['firstname']);
            $lastname = trim($admin['lastname'] ?? '') ?: $company['name'];
            $user = User::create([
                'company_id' => $cid,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'username' => LinkedUserService::uniqueUsername($firstname.$lastname),
                'email' => $admin['email'],
                'password' => Hash::make($admin['password']),
                'phone' => $company['phone'] ?? '',
                'role_id' => $roleId,
                'type' => 'admin',
                'statut' => 1,
                'is_all_warehouses' => 1,
                'record_view' => 1,
            ]);
            role_user::create(['user_id' => $user->id, 'role_id' => $roleId]);

            // ...and its employee record.
            $employeeId = DB::table('employees')->insertGetId([
                'company_id' => $cid,
                'user_id' => $user->id,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => 'other',
                'joining_date' => $now->toDateString(),
                'department_id' => $departmentId,
                'designation_id' => $designationId,
                'office_shift_id' => $shiftId,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            return ['company' => $co, 'user' => $user, 'employee_id' => $employeeId];
        });
    }
}
