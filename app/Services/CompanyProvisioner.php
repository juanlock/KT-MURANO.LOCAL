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
 *  - its menu: a copy of the template company's layout (the SuperAdmin's
 *    current platform order), not the factory order
 *  - a main warehouse (set as the Default Warehouse)
 *  - a Walk-in Customer (set as the Default Customer)
 *  - the default chart of accounts and expense categories
 *    (CompanyAccountingSeeder)
 *  - HR basics: an office shift, an "Administration" department and an
 *    "Administrator" designation (employees need all three)
 *  - the company's admin user (type admin, all warehouses) + its employee
 *  - optionally a helpdesk user (role "Helpdesk": works tickets, reads the
 *    intranet) + its employee, and the company's helpdesk settings row
 *
 * Demo data is NOT generated here -- the caller runs DemoRunner afterwards,
 * outside this transaction, so a demo failure never undoes the company.
 */
class CompanyProvisioner
{
    /**
     * @param  array  $company  name, email?, phone?, country?, default_language? (names of the defaults)
     * @param  array  $admin    firstname, lastname?, email, password, role? (role name)
     * @param  array|null  $helpdesk  firstname, lastname?, email, password -- the helpdesk agent
     * @return array{company: Company, user: User, employee_id: int, helpdesk_user: ?User}
     */
    public static function create(array $company, array $admin, ?array $helpdesk = null): array
    {
        return DB::transaction(function () use ($company, $admin, $helpdesk) {
            $now = now();
            // The default names below in the company's language (2026-10-26: a Spanish company got
            // "Regular Hours / Administration / Administrator / Main Location").
            $es = str_starts_with((string) ($company['default_language'] ?? ''), 'es');
            $L = fn ($en, $sp) => $es ? $sp : $en;

            $co = Company::create([
                'name' => $company['name'],
                'email' => $company['email'] ?? null,
                'phone' => $company['phone'] ?? null,
                'country' => $company['country'] ?? null,
            ]);
            $cid = (int) $co->id;

            // Roles: copy the template company's, permissions included.
            $roleMap = [];
            // Only the standard set (2026-11-01, CompanyRoles); Supplier / Sales Agent / Online Store come on demand.
            $templateRoles = DB::table('roles')->where('company_id', Setting::TEMPLATE_COMPANY_ID)->whereNull('deleted_at')
                ->whereIn('name', \App\Support\CompanyRoles::STANDARD)->get();
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
                'company_id' => $cid, 'name' => $L('Main Location', 'Local principal'),
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

            // Menu: the SuperAdmin's current platform layout (the template
            // company's rows), not the factory order of config/menu.js.
            self::copyTemplateMenu($cid);

            // HR basics every employee needs.
            $shiftId = DB::table('office_shifts')->insertGetId([
                'company_id' => $cid, 'name' => $L('Regular Hours 9:00-17:00', 'Horario regular 9:00-17:00'),
                'monday_in' => '09:00AM', 'monday_out' => '17:00PM',
                'tuesday_in' => '09:00AM', 'tuesday_out' => '17:00PM',
                'wednesday_in' => '09:00AM', 'wednesday_out' => '17:00PM',
                'thursday_in' => '09:00AM', 'thursday_out' => '17:00PM',
                'friday_in' => '09:00AM', 'friday_out' => '17:00PM',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $departmentId = DB::table('departments')->insertGetId([
                'company_id' => $cid, 'department' => $L('Administration', 'Administración'), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $designationId = DB::table('designations')->insertGetId([
                'company_id' => $cid, 'department_id' => $departmentId, 'designation' => $L('Administrator', 'Administrador'),
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // The company's admin user.
            foreach (\App\Support\CompanyRoles::STANDARD as $std) {
                $roleMap[$std] ??= \App\Support\CompanyRoles::ensure($cid, $std);
            }
            $roleName = $admin['role'] ?? \App\Support\CompanyRoles::ADMIN;
            $roleId = $roleMap[$roleName] ?? $roleMap[\App\Support\CompanyRoles::ADMIN];
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

            // Helpdesk: settings row always; the agent user when asked for.
            \App\Models\HelpdeskSetting::forCompany($cid);
            $helpdeskUser = null;
            if ($helpdesk && ! empty($helpdesk['email'])) {
                $hdRole = $roleMap['Helpdesk'] ?? $roleMap['Employee'] ?? $roleId;
                $hFirst = trim($helpdesk['firstname'] ?? '') ?: 'Helpdesk';
                $hLast = trim($helpdesk['lastname'] ?? '') ?: $company['name'];
                $helpdeskUser = User::create([
                    'company_id' => $cid,
                    'firstname' => $hFirst,
                    'lastname' => $hLast,
                    'username' => LinkedUserService::uniqueUsername($hFirst.$hLast),
                    'email' => $helpdesk['email'],
                    'password' => Hash::make($helpdesk['password']),
                    'phone' => $company['phone'] ?? '',
                    'role_id' => $hdRole,
                    'type' => 'employee',
                    'statut' => 1,
                    'is_all_warehouses' => 1,
                    'record_view' => 1,
                ]);
                role_user::create(['user_id' => $helpdeskUser->id, 'role_id' => $hdRole]);
                $hdDesignation = DB::table('designations')->insertGetId([
                    'company_id' => $cid, 'department_id' => $departmentId, 'designation' => 'Helpdesk Agent',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('employees')->insert([
                    'company_id' => $cid,
                    'user_id' => $helpdeskUser->id,
                    'firstname' => $hFirst,
                    'lastname' => $hLast,
                    'username' => $helpdeskUser->username,
                    'email' => $helpdeskUser->email,
                    'phone' => $helpdeskUser->phone,
                    'gender' => 'other',
                    'joining_date' => $now->toDateString(),
                    'department_id' => $departmentId,
                    'designation_id' => $hdDesignation,
                    'office_shift_id' => $shiftId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Default chart of accounts + expense categories (English/Spanish).
            CompanyAccountingSeeder::seed($cid);

            return ['company' => $co, 'user' => $user, 'employee_id' => $employeeId, 'helpdesk_user' => $helpdeskUser];
        });
    }

    /**
     * The company's menu = a copy of the template company's (AdminSystem, 999):
     * the order / grouping / hidden items the SuperAdmin set for the platform.
     * Nothing when the company already has a layout or the template has none.
     */
    public static function copyTemplateMenu(int $companyId): int
    {
        if (DB::table('menu_positions')->where('company_id', $companyId)->exists()) {
            return 0;
        }
        $now = now();
        $rows = DB::table('menu_positions')->where('company_id', Setting::TEMPLATE_COMPANY_ID)->get()
            ->map(fn ($r) => array_merge((array) $r, ['id' => null, 'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now]))
            ->map(fn ($r) => array_diff_key($r, ['id' => true]))->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('menu_positions')->insert($chunk);
        }

        return count($rows);
    }
}
