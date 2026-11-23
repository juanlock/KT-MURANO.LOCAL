<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sign in with the username (user's rule, 2026-11-22): the standard and demo logins every company gets
 * ({slug}-admin@kcoltech.com, {slug}-manager@, {slug}-customer@, turismo-pos-lima@...) sign in with the part of their
 * email before "@kcoltech.com" -- turismo-admin, turismo-customer, hordex-admin -- so they are predictable. Applied when a
 * login is created (User model) and after every migrate (AppServiceProvider), for the ones made with DB::table().
 */
class StandardUsernames
{
    /** The username a standard login should have, or null when the email isn't a standard one. */
    public static function forEmail(?string $email): ?string
    {
        if (! preg_match('/^([a-z0-9]+(?:-[a-z0-9]+)+)@kcoltech\.com$/i', trim((string) $email), $m)) {
            return null; // standard logins always have a hyphen: {slug}-{role}
        }

        return strtolower($m[1]);
    }

    /** Every standard login whose username differs gets the predictable one, when no other login holds it. */
    public static function sync(): int
    {
        $n = 0;
        foreach (DB::table('users')->where('email', 'like', '%-%@kcoltech.com')->whereNull('deleted_at')->get(['id', 'email', 'username']) as $u) {
            $want = self::forEmail($u->email);
            if (! $want || $u->username === $want || DB::table('users')->where('username', $want)->where('id', '!=', $u->id)->exists()) {
                continue;
            }
            DB::table('users')->where('id', $u->id)->update(['username' => $want]);
            $n++;
        }

        return $n;
    }
}
