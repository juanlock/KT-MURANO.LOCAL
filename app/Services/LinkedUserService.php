<?php

namespace App\Services;

use App\Models\Role;
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

    private static function uniqueUsername(string $seed): string
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
