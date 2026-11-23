<?php

namespace App\Support;

use App\Models\User;

/**
 * Who is signing in (user's rule, 2026-11-22: "vamos a trabajar con usuario"). Companies never know each other, so an
 * email can repeat across companies (and inside one, staff vs customer); the USERNAME is the sign-in name and is unique in
 * the whole install. A login may also sign in with its email only when its IT Admin / the SuperAdmin turned that on
 * (users.email_login) AND no other login behind the same door has that email in all of Murano. The SuperAdmin keeps its
 * email (unique install-wide).
 *
 * $door: 'staff' (/login, api/getAccessToken -- every type but customer) or 'customer' (the customer portal).
 */
class LoginLookup
{
    public static function find(string $input, string $door = 'staff'): ?User
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $base = fn () => User::query()->whereNull('deleted_at')
            ->where('type', $door === 'customer' ? '=' : '!=', 'customer');

        $byName = $base()->where('username', $input)->first();
        if ($byName) {
            return $byName;
        }
        if (! str_contains($input, '@')) {
            return null;
        }
        $same = $base()->where('email', $input)->get();
        if ($same->count() !== 1) {
            return null; // repeated in Murano: only the username tells which one
        }
        $u = $same->first();

        return $u->type === 'SuperAdmin' || (int) ($u->email_login ?? 0) === 1 ? $u : null;
    }

    /** May this login turn on "sign in with my email"? Its email must not repeat behind the same door in all of Murano. */
    public static function emailIsFree(User $user): bool
    {
        $email = trim((string) $user->email);
        if ($email === '' || str_ends_with($email, '@placeholder.murano.local')) {
            return false;
        }

        return ! User::query()->whereNull('deleted_at')->where('id', '!=', $user->id)->where('email', $email)
            ->where('type', $user->type === 'customer' ? '=' : '!=', 'customer')->exists();
    }
}
