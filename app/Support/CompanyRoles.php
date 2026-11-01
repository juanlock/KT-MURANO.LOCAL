<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The roles every company has (user's rule, 2026-11-01): each company its own
 * group, by default only
 *   Admin Compañía -- every form of that company (the old Admin-ALL);
 *   Helpdesk;
 *   Website        -- Website management + Portfolio;
 *   Formularios    -- the forms, read only (no new form, no delete);
 *   Customer, Employee.
 * Supplier / Sales Agent / Online Store are created when a login of that type is
 * (LinkedUserService), and a business's own roles (hospital, school, Ruba's
 * Supervisor / Operator...) stay. Owner, Admin, AdminRG, AdminNE, Admin-ALL and
 * Info are gone: their users moved to Admin Compañía (Info -> Formularios).
 * The SuperAdmin keeps its Owner role in AdminSystem (999), the only one with
 * every company.
 */
class CompanyRoles
{
    public const ADMIN = 'Admin Compañía';

    public const FORMS = 'Formularios';

    public const WEBSITE = 'Website';

    public const STANDARD = [self::ADMIN, 'Helpdesk', self::WEBSITE, self::FORMS, 'Customer', 'Employee'];

    /** Created on demand when a login of that type is (LinkedUserService). */
    public const AUTO = ['Supplier', 'Sales Agent', 'Online Store'];

    /** Old admin roles: their users -> Admin Compañía. */
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
            // A company created before 2026-11-01 without the template's Admin Compañía yet.
            $src = DB::table('roles')->where('company_id', $tpl)->whereIn('name', [self::ADMIN, 'Admin-ALL'])->whereNull('deleted_at')
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

        // 1. Admin Compañía = the company's Admin-ALL renamed (keeps its permissions and users).
        $admin = $roles()->firstWhere('name', self::ADMIN);
        if (! $admin && ($old = $roles()->firstWhere('name', 'Admin-ALL'))) {
            DB::table('roles')->where('id', $old->id)->update(['name' => self::ADMIN, 'label' => self::ADMIN,
                'description' => 'Company administrator: every form and module of this company.', 'updated_at' => now()]);
            $log[] = 'Admin-ALL -> '.self::ADMIN;
        }
        $adminId = self::ensure($companyId, self::ADMIN);

        // 2. Old admin roles: users -> Admin Compañía, role removed (AdminSystem keeps the SuperAdmin's Owner).
        foreach ($roles() as $r) {
            if (in_array($r->name, self::OLD_ADMIN, true) && (int) $r->id !== $adminId && ! ($isTemplate && $r->name === 'Owner')) {
                $n = self::moveUsers((int) $r->id, $adminId);
                self::drop((int) $r->id);
                $log[] = "{$r->name} removed ($n users -> ".self::ADMIN.')';
            }
        }

        // 3. Info -> Formularios (read only).
        $formsId = self::ensure($companyId, self::FORMS);
        DB::table('roles')->where('id', $formsId)->update(['description' => self::DEFINED[self::FORMS][0], 'home_path' => self::DEFINED[self::FORMS][1]]);
        DB::table('permission_role')->where('role_id', $formsId)->delete();
        DB::table('permission_role')->insert(DB::table('permissions')->whereIn('name', self::DEFINED[self::FORMS][2])->pluck('id')
            ->map(fn ($p) => ['permission_id' => $p, 'role_id' => $formsId])->all());
        if ($info = $roles()->firstWhere('name', 'Info')) {
            $n = self::moveUsers((int) $info->id, $formsId);
            self::drop((int) $info->id);
            $log[] = "Info removed ($n users -> ".self::FORMS.')';
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
