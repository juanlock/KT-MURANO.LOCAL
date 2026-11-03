<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The roles every company has (user's rules, 2026-11-01 / 2026-11-02): each
 * company its own group, by default only
 *   IT Admin       -- the company's IT person: every form of that company
 *                     ("Admin Compañía" 11-01..11-02);
 *   Manager        -- runs the business: the main modules, no configuration;
 *   Helpdesk;
 *   Website        -- Website management + Portfolio;
 *   Info           -- the forms (contact forms), read only ("Formularios" 11-01..11-02);
 *   Customer, Employee.
 * Supplier / Sales Agent / Online Store are created when a login of that type is
 * (LinkedUserService), and a business's own roles (hospital, school, clinic,
 * restaurant, Ruba's Supervisor / Operator, New Era's Staff) stay. Owner, Admin,
 * AdminRG, AdminNE, Admin-ALL are gone (users -> IT Admin); Manager RG /
 * ManagerNE -> Manager. The SuperAdmin's role (SuperAdmin, ex Owner) lives only
 * in AdminSystem (999).
 */
class CompanyRoles
{
    /** The company's IT admin (user's rule, 2026-11-02: "IT Admin"). */
    public const ADMIN = 'IT Admin';

    /** Names the IT Admin role has had: renamed to ADMIN wherever they are left. */
    public const OLD_ADMIN_NAMES = ['Admin Compañía'];

    /** The {slug}-info login's role: the forms (contact forms) read only. "Formularios" until 2026-11-02. */
    public const FORMS = 'Info';

    /** Names the Info role has had (2026-11-01 "Formularios"). */
    public const OLD_FORMS = ['Formularios'];

    /**
     * Manager (user's rule, 2026-11-02): runs the business but not the system --
     * every main module of the company (its IT Admin's permissions) except
     * configuration: settings, users and roles, HRM setup (company, departments,
     * designations, shifts), integrations. Replaces each company's own manager
     * roles (Ruba's "Manager RG", New Era's "ManagerNE").
     */
    public const MANAGER = 'Manager';

    /** Company-made manager roles replaced by Manager (users moved, role removed). */
    public const OLD_MANAGER = ['Manager RG', 'ManagerNE'];

    /** What a Manager never gets: the configuration side of the IT Admin. */
    public const MANAGER_EXCLUDED = [
        'setting_system', 'appearance_settings', 'datatable_customize', 'notification_template', 'warehouse', 'warehouse_locations',
        'network_security', 'login_security', 'login_device_management', 'company_logins_view', 'report_device_management',
        'report_error_logs', 'customer_display_screen_setup', 'Store_settings_view', 'marketing_settings', 'hms_setup',
        'kt_logs_view', 'company_manuals_view', 'roles_matrix_view',
        'users_view', 'users_add', 'users_edit', 'users_delete', 'users_report',
        'permissions_view', 'permissions_add', 'permissions_edit', 'permissions_delete',
        'company', 'department', 'designation', 'office_shift',
        'shopify_stores', 'shopify_sync', 'shopify_logs',
    ];

    /** A Manager also sees every employee's file and booking, and records its own attendance. */
    public const MANAGER_EXTRA = ['employee_view_all', 'booking_other_staff', 'attendance_self'];

    public const WEBSITE = 'Website';

    /**
     * The SuperAdmin users' role, in AdminSystem (999) only: every permission
     * (SuperAdminAccess::sync). Called "Owner" until 2026-11-02 (user's rule: the
     * same name as the user type). Never offered to a company's users.
     */
    public const SUPERADMIN = 'SuperAdmin';

    /** Names the SuperAdmin role has had: protected / never offered under either. */
    public const SUPERADMIN_NAMES = [self::SUPERADMIN, 'Owner'];

    public const STANDARD = [self::ADMIN, self::MANAGER, 'Helpdesk', self::WEBSITE, self::FORMS, 'Customer', 'Employee'];

    /** Created on demand when a login of that type is (LinkedUserService). */
    public const AUTO = ['Supplier', 'Sales Agent', 'Online Store'];

    /** Old admin roles: their users -> IT Admin. */
    public const OLD_ADMIN = ['Owner', 'Admin', 'Admin-ALL', 'AdminRG', 'AdminNE'];

    /** name => [description, home page, permissions] for the roles defined here. */
    public const DEFINED = [
        self::WEBSITE => ['Website management and Portfolio of the company.', '/website/settings', ['Store_settings_view', 'portfolio_manage']],
        self::FORMS => ['The company\'s forms and their submissions, read only (no new form, no delete).', '/forms', ['forms_view']],
    ];

    /** The role's id in that company; created when missing (permissions from the template company, else DEFINED). */
    public static function ensure(int $companyId, string $name): ?int
    {
        $id = DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->value('id');
        if ($id) {
            return (int) $id;
        }
        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        $src = $companyId === $tpl ? null
            : DB::table('roles')->where('company_id', $tpl)->where('name', $name)->whereNull('deleted_at')->first();
        if (! $src && $name === self::ADMIN) {
            // A template still under an older name (Admin Compañía, Admin-ALL).
            $src = DB::table('roles')->where('company_id', $tpl)->whereIn('name', array_merge([self::ADMIN, 'Admin-ALL'], self::OLD_ADMIN_NAMES))->whereNull('deleted_at')
                ->orderByRaw('name = ? desc', [self::ADMIN])->first();
        }
        $def = self::DEFINED[$name] ?? null;
        if (! $src && ! $def && ! in_array($name, array_merge(self::STANDARD, self::AUTO), true)) {
            return null;
        }
        $now = now();
        $id = DB::table('roles')->insertGetId([
            'company_id' => $companyId, 'name' => $name, 'label' => $src->label ?? $name,
            'description' => $def[0] ?? ($src->description ?? $name), 'home_path' => $def[1] ?? ($src->home_path ?? null),
            'status' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $perms = $def
            ? DB::table('permissions')->whereIn('name', $def[2])->pluck('id')
            : ($src ? DB::table('permission_role')->where('role_id', $src->id)->pluck('permission_id') : collect());
        if ($perms->isNotEmpty()) {
            DB::table('permission_role')->insert($perms->unique()->map(fn ($p) => ['permission_id' => $p, 'role_id' => $id])->values()->all());
        }

        return (int) $id;
    }

    /**
     * The company's Manager role (made when missing) with its permissions: the
     * company's IT Admin ones minus MANAGER_EXCLUDED and the SuperAdmin-only
     * settings (SettingsAccess::RESTRICTED), plus MANAGER_EXTRA. Returns its id.
     */
    public static function syncManager(int $companyId): ?int
    {
        $adminId = DB::table('roles')->where('company_id', $companyId)->where('name', self::ADMIN)->whereNull('deleted_at')->value('id');
        if (! $adminId) {
            return null;
        }
        $id = DB::table('roles')->where('company_id', $companyId)->where('name', self::MANAGER)->whereNull('deleted_at')->value('id');
        if (! $id) {
            $id = DB::table('roles')->insertGetId([
                'company_id' => $companyId, 'name' => self::MANAGER, 'label' => self::MANAGER,
                'description' => 'Runs the business: every main module, no configuration (settings, users, roles, HRM setup, integrations).',
                'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $excluded = array_merge(self::MANAGER_EXCLUDED, array_keys(SettingsAccess::RESTRICTED));
        $perms = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permission_role.role_id', $adminId)->whereNotIn('permissions.name', $excluded)->where('permissions.name', 'not like', 'menu\_hide:%')
            ->pluck('permissions.id')
            ->merge(DB::table('permissions')->whereIn('name', self::MANAGER_EXTRA)->pluck('id'))->unique();
        DB::table('permission_role')->where('role_id', $id)->delete();
        DB::table('permission_role')->insert($perms->map(fn ($p) => ['permission_id' => $p, 'role_id' => $id])->values()->all());

        return (int) $id;
    }

    /** Moves every user of role $from to role $to (role_user + users.role_id). */
    private static function moveUsers(int $from, int $to): int
    {
        $uids = DB::table('role_user')->where('role_id', $from)->pluck('user_id');
        foreach ($uids as $uid) {
            DB::table('role_user')->where('user_id', $uid)->where('role_id', $from)->delete();
            if (! DB::table('role_user')->where('user_id', $uid)->where('role_id', $to)->exists()) {
                DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $to]);
            }
        }
        DB::table('users')->where('role_id', $from)->update(['role_id' => $to]);

        return $uids->count();
    }

    private static function drop(int $roleId): void
    {
        DB::table('roles')->where('id', $roleId)->update(['deleted_at' => now()]);
    }

    /**
     * One company to the standard set (the 2026-11-01 migration). Safe to run twice.
     * @return array<string> what changed
     */
    public static function normalize(int $companyId): array
    {
        $isTemplate = $companyId === \App\Models\Setting::TEMPLATE_COMPANY_ID;
        $roles = fn () => DB::table('roles')->where('company_id', $companyId)->whereNull('deleted_at')->get(['id', 'name']);
        $log = [];

        // 1. IT Admin = the company's Admin Compañía / Admin-ALL renamed (keeps its permissions and users).
        $admin = $roles()->firstWhere('name', self::ADMIN);
        if (! $admin && ($old = $roles()->first(fn ($r) => in_array($r->name, array_merge(self::OLD_ADMIN_NAMES, ['Admin-ALL']), true)))) {
            DB::table('roles')->where('id', $old->id)->update(['name' => self::ADMIN, 'label' => self::ADMIN,
                'description' => 'Company administrator: every form and module of this company.', 'updated_at' => now()]);
            $log[] = $old->name.' -> '.self::ADMIN;
        }
        $adminId = self::ensure($companyId, self::ADMIN);

        // 2. Old admin roles: users -> IT Admin, role removed (AdminSystem keeps the SuperAdmin's Owner).
        foreach ($roles() as $r) {
            if (in_array($r->name, array_merge(self::OLD_ADMIN, self::OLD_ADMIN_NAMES), true) && (int) $r->id !== $adminId && ! ($isTemplate && in_array($r->name, self::SUPERADMIN_NAMES, true))) {
                $n = self::moveUsers((int) $r->id, $adminId);
                self::drop((int) $r->id);
                $log[] = "{$r->name} removed ($n users -> ".self::ADMIN.')';
            }
        }

        // 3. Info (read only): an old-named one (Formularios) is renamed, keeping its users.
        if (! $roles()->firstWhere('name', self::FORMS) && ($old = $roles()->first(fn ($r) => in_array($r->name, self::OLD_FORMS, true)))) {
            DB::table('roles')->where('id', $old->id)->update(['name' => self::FORMS, 'label' => self::FORMS, 'updated_at' => now()]);
            $log[] = "{$old->name} -> ".self::FORMS;
        }
        $formsId = self::ensure($companyId, self::FORMS);
        DB::table('roles')->where('id', $formsId)->update(['description' => self::DEFINED[self::FORMS][0], 'home_path' => self::DEFINED[self::FORMS][1]]);
        DB::table('permission_role')->where('role_id', $formsId)->delete();
        DB::table('permission_role')->insert(DB::table('permissions')->whereIn('name', self::DEFINED[self::FORMS][2])->pluck('id')
            ->map(fn ($p) => ['permission_id' => $p, 'role_id' => $formsId])->all());
        foreach ($roles() as $r) {
            if (in_array($r->name, self::OLD_FORMS, true) && (int) $r->id !== $formsId) {
                $n = self::moveUsers((int) $r->id, $formsId);
                self::drop((int) $r->id);
                $log[] = "{$r->name} removed ($n users -> ".self::FORMS.')';
            }
        }

        // 3b. Manager: the main modules without configuration; the company's own manager roles merge into it.
        $managerId = self::syncManager($companyId);
        foreach ($roles() as $r) {
            if ($managerId && in_array($r->name, self::OLD_MANAGER, true)) {
                $n = self::moveUsers((int) $r->id, $managerId);
                self::drop((int) $r->id);
                $log[] = "{$r->name} removed ($n users -> ".self::MANAGER.')';
            }
        }

        // 4. The rest of the standard set.
        foreach (['Helpdesk', self::WEBSITE, 'Customer', 'Employee'] as $name) {
            self::ensure($companyId, $name);
        }

        // 5. Automatic roles nobody uses (created again when a login of that type is). The template keeps them.
        if (! $isTemplate) {
            foreach ($roles() as $r) {
                if (in_array($r->name, self::AUTO, true) && ! DB::table('role_user')->where('role_id', $r->id)->exists()
                    && ! DB::table('users')->where('role_id', $r->id)->whereNull('deleted_at')->exists()) {
                    self::drop((int) $r->id);
                    $log[] = "{$r->name} removed (unused)";
                }
            }
        }

        return $log;
    }
}
