<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Login emails (user's rule, 2026-11-22: sign in with the USERNAME; "personal, customer, customer online y cualquier tipo
 * de usuario de una empresa es aislado... puede repetirse... solo los superadmin del sistema son únicos"): an email can
 * repeat freely across logins and companies, since the username (unique in all of Murano) is what signs in. Only a
 * SuperAdmin's email is unique install-wide: no other login may take it, and a SuperAdmin can't take a used one.
 * Signing in with the email is a per-login switch (users.email_login, App\Support\LoginLookup).
 */
class LoginEmails
{
    /**
     * Is $email unavailable? $companyId null = the login is (or becomes) a SuperAdmin: any other login with it counts.
     * Otherwise only a SuperAdmin's email counts. $exceptId = the login being edited. ($customer is kept for callers.)
     */
    public static function taken(string $email, ?int $companyId, ?int $exceptId = null, bool $customer = false): bool
    {
        return DB::table('users')->where('email', trim($email))->whereNull('deleted_at')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($companyId !== null, fn ($q) => $q->where('type', 'SuperAdmin'))
            ->exists();
    }

    /** Validation rule (see taken()). */
    public static function rule(?int $companyId, ?int $exceptId = null, bool $customer = false): \Closure
    {
        return function ($attribute, $value, $fail) use ($companyId, $exceptId, $customer) {
            if ($value !== null && $value !== '' && self::taken((string) $value, $companyId, $exceptId, $customer)) {
                $fail(__('This email belongs to the system administrator; use another one.'));
            }
        };
    }
}
