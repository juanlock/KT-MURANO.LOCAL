<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Standard logins and passwords of a company (user's rule, 2026-10-25), for
 * new companies and for the demos (so every role can be tested):
 *  - three access logins, each with its own role: {slug}-admin (Admin-ALL),
 *    {slug}-helpdesk (Helpdesk), {slug}-info (Info) @kcoltech.com;
 *  - the company's default passwords per login type (Defaults -> Users):
 *    employee, customer, and the other types (supplier, sales agent, store).
 * The demos create their people (doctors, nurses, teachers, patients...)
 * through LinkedUserService, so they get these defaults by their type.
 */
class CompanyLogins
{
    /** Default password per login type. */
    public const PASSWORDS = [
        'employee' => 'Empleado2020',
        'customer' => 'Cliente2024',
        'supplier' => 'C0ntr0l123',
        'sales_agent' => 'C0ntr0l123',
        'online_store' => 'C0ntr0l123',
    ];

    /** Password of the access logins (admin / helpdesk / info). */
    public const ACCESS_PASSWORD = 'C0ntr0l123$';

    /** access => [role, permissions of the role when it has to be made (null = copy the template's)]. */
    public const ACCESS = [
        // 2026-11-01 (CompanyRoles): Admin-ALL -> Admin Compañía, Info -> Formularios (read only).
        'admin' => [CompanyRoles::ADMIN, null],
        'helpdesk' => ['Helpdesk', ['helpdesk_view', 'helpdesk_manage', 'intranet_view', 'email_office_view', 'Customers_view']],
        'info' => [CompanyRoles::FORMS, ['forms_view']],
    ];

    /** The company's prefix for its logins: the one its admin login already uses, else its name. */
    public static function slug(int $companyId): string
    {
        $email = DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')->where('email', 'like', '%-admin@kcoltech.com')->value('email');
        if ($email) {
            return Str::before($email, '-admin@');
        }

        return Str::slug((string) DB::table('companies')->where('id', $companyId)->value('name'), '') ?: 'company'.$companyId;
    }

    public static function email(int $companyId, string $access): string
    {
        return self::slug($companyId).'-'.$access.'@kcoltech.com';
    }

    /**
     * Defaults per type -- only where the company has none ($force: always).
     * Hashed, like Defaults -> Users stores them.
     */
    public static function applyDefaults(int $companyId, bool $force = false): void
    {
        $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first();
        if (! $s) {
            return;
        }
        $set = [];
        foreach (self::PASSWORDS as $type => $plain) {
            $col = 'default_password_'.$type;
            if (property_exists($s, $col) && ($force || empty($s->{$col}))) {
                $set[$col] = Hash::make($plain);
            }
        }
        if ($set) {
            DB::table('settings')->where('id', $s->id)->update($set + ['updated_at' => now()]);
        }
    }

    /** The role of an access, made when missing (Admin-ALL / Helpdesk from the template, Info new). */
    public static function role(int $companyId, string $access): int
    {
        [$name, $perms] = self::ACCESS[$access];
        $id = DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->value('id')
            ?? CompanyRoles::ensure($companyId, $name);
        if ($id) {
            return (int) $id;
        }
        $template = DB::table('roles')->where('company_id', Setting::TEMPLATE_COMPANY_ID)->where('name', $name)->whereNull('deleted_at')->first();
        $id = DB::table('roles')->insertGetId([
            'company_id' => $companyId, 'name' => $name,
            'label' => $template->label ?? $name, 'description' => $template->description ?? ucfirst($access).' access',
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permIds = $perms === null && $template
            ? DB::table('permission_role')->where('role_id', $template->id)->pluck('permission_id')
            : DB::table('permissions')->whereIn('name', $perms ?? [])->pluck('id');
        if ($permIds->isNotEmpty()) {
            DB::table('permission_role')->insert($permIds->unique()->map(fn ($p) => ['permission_id' => $p, 'role_id' => $id])->values()->all());
        }

        return (int) $id;
    }

    /**
     * The three access logins with their roles and the standard password.
     * An existing login (same email) keeps its role and gets the password
     * only with $resetPasswords. Returns [access => user id].
     */
    public static function ensureAccess(int $companyId, bool $resetPasswords = false): array
    {
        $out = [];
        $company = (string) DB::table('companies')->where('id', $companyId)->value('name');
        foreach (array_keys(self::ACCESS) as $access) {
            $email = self::email($companyId, $access);
            $user = DB::table('users')->where('email', $email)->whereNull('deleted_at')->first();
            if ($user) {
                abort_unless((int) $user->company_id === $companyId, 422, "The login {$email} belongs to another company.");
                if ($resetPasswords) {
                    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(self::ACCESS_PASSWORD), 'statut' => 1, 'updated_at' => now()]);
                }
                // Helpdesk / info always carry their own role (the admin keeps Owner / Admin-ALL...).
                if ($access !== 'admin') {
                    $roleId = self::role($companyId, $access);
                    DB::table('role_user')->where('user_id', $user->id)->delete();
                    DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
                    DB::table('users')->where('id', $user->id)->update(['role_id' => $roleId]);
                }
                $out[$access] = (int) $user->id;
                continue;
            }
            $roleId = self::role($companyId, $access);
            // The company already has this access under another email (e.g. the
            // admin typed in Companies -> Add): no second one.
            $holder = DB::table('role_user as ru')->join('users as u', 'u.id', '=', 'ru.user_id')
                ->where('ru.role_id', $roleId)->where('u.company_id', $companyId)->whereNull('u.deleted_at')
                ->whereIn('u.type', ['admin', 'employee'])->value('u.id');
            if ($holder) {
                $out[$access] = (int) $holder;
                continue;
            }
            $username = self::slug($companyId).'-'.$access;
            if (DB::table('users')->where('username', $username)->exists()) {
                $username .= '-'.$companyId;
            }
            $id = DB::table('users')->insertGetId([
                'company_id' => $companyId, 'firstname' => ucfirst($access), 'lastname' => $company,
                'username' => $username, 'email' => $email, 'password' => Hash::make(self::ACCESS_PASSWORD),
                'phone' => '', 'role_id' => $roleId, 'type' => 'admin', 'statut' => 1,
                'is_all_warehouses' => 1, 'record_view' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_user')->insert(['user_id' => $id, 'role_id' => $roleId]);
            $out[$access] = $id;
        }

        return $out;
    }

    /**
     * A test / demo company: defaults forced, the access logins, and every
     * existing login of a type with a default password set to it (so any
     * demo person can sign in). Never for a real company.
     */
    public static function applyToTestCompany(int $companyId): array
    {
        self::applyDefaults($companyId, true);
        $access = self::ensureAccess($companyId, true);
        $map = ['employee' => 'employee', 'customer' => 'customer', 'supplier' => 'supplier', 'sales_agent' => 'sales_agent', 'Online_store' => 'online_store'];
        $counts = [];
        foreach ($map as $type => $key) {
            // The access logins keep the access password (a helpdesk agent is an employee too).
            $counts[$type] = DB::table('users')->where('company_id', $companyId)->where('type', $type)->whereNull('deleted_at')
                ->whereNotIn('id', array_values($access))
                ->update(['password' => Hash::make(self::PASSWORDS[$key]), 'updated_at' => now()]);
        }
        // Test customers can sign in to the customer portal too.
        $counts['portal_on'] = DB::table('users')->where('company_id', $companyId)->where('type', 'customer')->whereNull('deleted_at')
            ->update(['portal_enabled' => 1, 'invitation_token' => null]);

        return ['access' => $access, 'reset' => $counts];
    }
}
