<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Provider;
use App\Models\SalesAgent;
use App\Models\User;
use App\Services\LinkedUserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Every role of a company has at least one user (user's rule, 2026-11-03):
 * the standard and automatic roles exist in every company (CompanyRoles), the
 * access roles have their {slug}-{access} login (CompanyLogins), and each
 * people role nobody holds gets a demo record with its login --
 * Customer, Employee, Supplier, Sales Agent, Online Store -- named "Demo"
 * in the company's language, email {slug}-{key}@kcoltech.com, the type's
 * standard password. They are the company's own records (not tagged as demo
 * data, so Remove demo data keeps them). Safe to run twice: a role that
 * already has a user, or an email already taken, is left alone.
 */
class CompanyDefaultPeople
{
    /** key => [role, login type, password key, English name, Spanish name]. */
    public const PEOPLE = [
        'customer' => ['Customer', 'customer', 'customer', 'Demo Customer', 'Cliente Demo'],
        'employee' => ['Employee', 'employee', 'employee', 'Demo Employee', 'Empleado Demo'],
        'supplier' => ['Supplier', 'supplier', 'supplier', 'Demo Supplier', 'Proveedor Demo'],
        'salesagent' => ['Sales Agent', 'sales_agent', 'sales_agent', 'Demo Sales Agent', 'Agente de Ventas Demo'],
        'store' => ['Online Store', 'Online_store', 'online_store', 'Demo Store Customer', 'Cliente Tienda Demo'],
    ];

    /** @return array<string> what was made */
    public static function ensure(int $companyId): array
    {
        $log = [];
        foreach (CompanyRoles::AUTO as $name) {
            CompanyRoles::ensure($companyId, $name);
        }
        CompanyLogins::ensureAccess($companyId);

        $es = str_starts_with((string) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('default_language'), 'es');
        foreach (self::PEOPLE as $key => [$role, $type, $pwKey, $en, $sp]) {
            $roleId = CompanyRoles::ensure($companyId, $role);
            if (! $roleId || self::holders($roleId) > 0) {
                continue;
            }
            $email = CompanyLogins::email($companyId, $key);
            if (DB::table('users')->where('email', $email)->exists()) {
                continue;
            }
            $userId = self::make($companyId, $key, $es ? $sp : $en, $email);
            if (! $userId) {
                $log[] = "$role: skipped (no department / designation / office shift)";

                continue;
            }
            // The type's standard password, so the role can be tried right away.
            User::whereKey($userId)->update(['password' => Hash::make(CompanyLogins::PASSWORDS[$pwKey]), 'statut' => 1]);
            $log[] = "$role: $email";
        }

        return $log;
    }

    /** Active users holding the role. */
    private static function holders(int $roleId): int
    {
        return DB::table('role_user')->join('users', 'users.id', '=', 'role_user.user_id')
            ->where('role_user.role_id', $roleId)->whereNull('users.deleted_at')->count();
    }

    /** The record and its login (LinkedUserService, like the modules' own forms); the user id. */
    private static function make(int $cid, string $key, string $name, string $email): ?int
    {
        switch ($key) {
            case 'customer':
            case 'store':
                $client = new Client;
                $client->name = $name;
                $client->code = (new \App\Http\Controllers\ClientController)->getNumberOrder();
                $client->email = $email;
                $client->company_id = $cid;
                $client->save();

                return $key === 'store'
                    ? LinkedUserService::ensureForClient($client, 'Online_store', 'Online Store')
                    : LinkedUserService::ensureForClient($client);

            case 'employee':
                [$dept, $des, $shift] = self::hrFrame($cid);
                if (! $dept || ! $des || ! $shift) {
                    return null;
                }
                [$first, $last] = explode(' ', $name, 2);
                $employee = Employee::create([
                    'company_id' => $cid, 'firstname' => $first, 'lastname' => $last, 'username' => $name,
                    'email' => $email, 'gender' => 'other', 'avatar' => 'no_avatar.png',
                    'department_id' => $dept, 'designation_id' => $des, 'office_shift_id' => $shift,
                    'joining_date' => now()->toDateString(), 'employment_type' => 'full_time',
                    'total_leave' => 0, 'remaining_leave' => 0, 'basic_salary' => 0,
                ]);

                return (int) DB::table('employees')->where('id', $employee->id)->value('user_id') ?: null;

            case 'supplier':
                $provider = new Provider;
                $provider->name = $name;
                $provider->code = (new \App\Http\Controllers\ProvidersController)->getNumberOrder();
                $provider->email = $email;
                $provider->company_id = $cid;
                $provider->save();
                $provider->user_id = LinkedUserService::provision($cid, 'supplier', 'Supplier', $name, $email);
                $provider->save();

                return (int) $provider->user_id;

            case 'salesagent':
                $userId = LinkedUserService::provision($cid, 'sales_agent', 'Sales Agent', $name, $email);
                SalesAgent::create(['company_id' => $cid, 'name' => $name, 'email' => $email, 'user_id' => $userId, 'is_active' => true]);
                // A sales agent is staff: an Employee too (same login), like Commissions -> Sales Agents.
                LinkedUserService::ensureEmployee(User::find($userId), $cid);

                return $userId;
        }

        return null;
    }

    /** The company's first department / designation / office shift (employees need all three). */
    private static function hrFrame(int $cid): array
    {
        $dept = DB::table('departments')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->value('id');
        $des = DB::table('designations')->where('company_id', $cid)->whereNull('deleted_at')
            ->when($dept, fn ($q) => $q->orderByRaw('department_id = ? desc', [$dept]))->orderBy('id')->value('id');
        $shift = DB::table('office_shifts')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->value('id');

        return [$dept, $des, $shift];
    }
}
