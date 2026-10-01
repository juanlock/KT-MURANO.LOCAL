<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\OfficeShift;
use App\Models\Provider;
use App\Models\Role;
use App\Models\SalesAgent;
use App\Models\User;
use App\Models\role_user;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Every Client/Employee is meant to have a matching `users` row (type
 * customer/employee/Online_store) -- clients.user_id and employees.user_id
 * both already exist as nullable/unique FKs for exactly this, but nothing
 * ever created one: not ClientController::store()/import(), not
 * hrm\EmployeesController::store(), not the storefront's own registration.
 * This is the one place that provisions that linked account, called from
 * all of them, so the rules (username/email collision handling, role
 * resolution, the role_user pivot row real permission-loading needs) live
 * in exactly one place.
 */
class LinkedUserService
{
    /**
     * Creates a new `users` row linked to a Client/Employee and returns its
     * id. Never call this if a linked user might already exist (unique
     * constraint on clients.user_id/employees.user_id) -- check first.
     */
    public static function provision(int $companyId, string $type, string $roleName, string $displayName, ?string $email = null, ?string $phone = null): int
    {
        $roleId = Role::where('company_id', $companyId)->where('name', $roleName)->value('id')
            ?? Role::where('company_id', $companyId)->where('name', 'Customer')->value('id');

        $username = self::uniqueUsername($displayName ?: $type);
        $finalEmail = self::uniqueEmail($email, $username);
        [$firstname, $lastname] = self::splitName($displayName);

        $user = User::create([
            'company_id' => $companyId,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'username' => $username,
            'email' => $finalEmail,
            // Backend-provisioned shadow account, not meant for direct
            // login unless/until a separate invite/reset-password flow
            // exists -- random, never surfaced anywhere.
            'password' => Hash::make(Str::random(32)),
            'phone' => $phone !== null && $phone !== '' ? $phone : '',
            'role_id' => $roleId,
            'type' => $type,
            'statut' => 1,
            'is_all_warehouses' => 0,
            'record_view' => 0,
        ]);

        role_user::create(['user_id' => $user->id, 'role_id' => $roleId]);

        return $user->id;
    }

    /**
     * The reverse of provision(): the `users` row already exists (created
     * directly from Settings -> Users), and this creates the matching
     * Client/Employee/SalesAgent row pointing back at it, plus assigns the
     * type's own per-company role (Employee/Customer/Sales Agent) --
     * mirroring the same 1-to-1 relationship provision() sets up from the
     * other direction. No-op for any other type (Admin/SuperAdmin never get
     * a linked record).
     *
     * Direct property assignment + save(), not ::create() -- Client/Employee
     * deliberately don't list company_id/user_id in $fillable (so a mass
     * assignment elsewhere can never forge them), matching how
     * ClientController/EmployeesController already set these two fields.
     *
     * @return string|null a warning key the caller can surface (e.g. 'employee_missing_hr_setup'), or null on success/no-op
     */
    public static function attachTypedRecord(User $user, string $type, int $companyId): ?string
    {
        $roleNames = ['employee' => 'Employee', 'customer' => 'Customer', 'sales_agent' => 'Sales Agent', 'supplier' => 'Supplier'];
        $roleName = $roleNames[$type] ?? null;
        if (! $roleName) {
            return null;
        }

        // Assign the type's own role first, independent of whether the
        // linked record below can actually be created -- a user shouldn't
        // end up stuck with the wrong role just because HR setup is missing.
        $roleId = Role::where('company_id', $companyId)->where('name', $roleName)->value('id');
        if ($roleId) {
            $user->role_id = $roleId;
            $user->save();
            role_user::where('user_id', $user->id)->delete();
            role_user::create(['user_id' => $user->id, 'role_id' => $roleId]);
        }

        if ($type === 'employee') {
            $departmentId = Department::where('company_id', $companyId)->value('id');
            $designationId = Designation::where('company_id', $companyId)->value('id');
            $officeShiftId = OfficeShift::where('company_id', $companyId)->value('id');
            if (! $departmentId || ! $designationId || ! $officeShiftId) {
                // employees.department_id/designation_id/office_shift_id are
                // NOT NULL -- can't create a valid row without them, and
                // guessing values would be worse than just telling the admin.
                return 'employee_missing_hr_setup';
            }
        }

        if ($type === 'employee') {
            $employee = new Employee;
            $employee->firstname = $user->firstname;
            $employee->lastname = $user->lastname;
            $employee->username = $user->username;
            $employee->email = $user->email;
            $employee->phone = $user->phone;
            $employee->gender = 'other';
            $employee->joining_date = now()->toDateString();
            $employee->company_id = $companyId;
            $employee->department_id = $departmentId;
            $employee->designation_id = $designationId;
            $employee->office_shift_id = $officeShiftId;
            $employee->user_id = $user->id;
            $employee->save();
        } elseif ($type === 'customer') {
            $client = new Client;
            $client->name = trim($user->firstname.' '.$user->lastname) ?: $user->username;
            $client->firstname = $user->firstname;
            $client->lastname = $user->lastname;
            $client->code = (new \App\Http\Controllers\ClientController)->getNumberOrder();
            $client->email = $user->email;
            $client->phone = $user->phone;
            $client->company_id = $companyId;
            $client->user_id = $user->id;
            $client->save();
        } elseif ($type === 'supplier') {
            $provider = new Provider;
            $provider->name = trim($user->firstname.' '.$user->lastname) ?: $user->username;
            $provider->code = (new \App\Http\Controllers\ProvidersController)->getNumberOrder();
            $provider->email = $user->email;
            $provider->phone = $user->phone;
            $provider->company_id = $companyId;
            $provider->user_id = $user->id;
            $provider->save();
        } elseif ($type === 'sales_agent') {
            SalesAgent::create([
                'company_id' => $companyId,
                'name' => trim($user->firstname.' '.$user->lastname) ?: $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'user_id' => $user->id,
                'is_active' => true,
            ]);
        }

        return null;
    }

    /**
     * Gives an existing Client its linked users row if it has none yet, in
     * the client's own company. Used where the account is only created once
     * something is confirmed: a public booking confirmed by staff, a web
     * store account once email-verified / admin-approved. Returns the new
     * user id, or null when the client already had one (nothing done).
     */
    public static function ensureForClient(Client $client, string $type = 'customer', string $roleName = 'Customer'): ?int
    {
        if ($client->user_id || ! $client->company_id) {
            return null;
        }
        $client->user_id = self::provision((int) $client->company_id, $type, $roleName, (string) $client->name, $client->email, $client->phone);
        $client->save();

        return $client->user_id;
    }

    public static function uniqueUsername(string $seed): string
    {
        $base = Str::slug($seed, '_');
        if ($base === '') {
            $base = 'user';
        }
        $username = $base;
        $i = 1;
        while (User::where('username', $username)->exists()) {
            $i++;
            $username = $base.$i;
        }

        return $username;
    }

    private static function uniqueEmail(?string $email, string $usernameFallback): string
    {
        $email = trim((string) $email);
        if ($email === '') {
            $email = strtolower($usernameFallback).'@placeholder.murano.local';
        }
        if (! User::where('email', $email)->exists()) {
            return $email;
        }
        // Real collision (a genuine email already used by some other user) --
        // disambiguate rather than fail the whole create.
        $at = strpos($email, '@');
        $local = $at !== false ? substr($email, 0, $at) : $email;
        $domain = $at !== false ? substr($email, $at) : '@placeholder.murano.local';

        return $local.'+'.Str::random(6).$domain;
    }

    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? ($name !== '' ? $name : 'User'), $parts[1] ?? ''];
    }
}
