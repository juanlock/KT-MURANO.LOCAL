<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Password rules (user's rules, 2026-10-27) for every password a person chooses
 * for themselves -- the forced change after a standard password, Profile, the
 * "forgot password" links, the customer portal: at least 8 characters with letters
 * and numbers, not a standard password (LoginSecurity::isStandardPassword), not
 * the login's email, and none of its last HISTORY passwords. Passwords an admin
 * sets for someone else (Users, System access) are not checked here: that person
 * is asked to change a standard one at sign-in.
 */
class PasswordPolicy
{
    public const MIN = 8;

    public const HISTORY = 3;

    /** Validation rules for the new password field (confirmed = a matching *_confirmation field). */
    public static function rules(?object $user, bool $confirmed = true): array
    {
        $es = LoginSecurity::es($user ? LoginSecurity::langOf($user) : null);

        return array_values(array_filter([
            'required', 'string', 'min:'.self::MIN, 'max:64', $confirmed ? 'confirmed' : null,
            function ($attr, $value, $fail) use ($user, $es) {
                $value = (string) $value;
                if (! preg_match('/\pL/u', $value) || ! preg_match('/\d/', $value)) {
                    return $fail($es ? 'La contraseña debe tener letras y números.' : 'The password needs letters and numbers.');
                }
                if (! $user) {
                    return;
                }
                if (LoginSecurity::isStandardPassword($user, $value)) {
                    return $fail($es ? 'No puede usar una contraseña estándar: elija una propia.' : 'You can\'t use a standard password: choose your own.');
                }
                if ($user->email && mb_strtolower($value) === mb_strtolower((string) $user->email)) {
                    return $fail($es ? 'La contraseña no puede ser su email.' : 'The password can\'t be your email.');
                }
                if (self::usedBefore($user, $value)) {
                    return $fail($es ? 'Esa contraseña ya la usó antes: elija una nueva (no puede repetir las últimas '.self::HISTORY.').' : 'You used that password before: choose a new one (the last '.self::HISTORY.' can\'t be repeated).');
                }
            },
        ]));
    }

    public static function usedBefore(object $user, string $plain): bool
    {
        $current = DB::table('users')->where('id', $user->id)->value('password');
        if ($current && Hash::check($plain, $current)) {
            return true;
        }
        foreach (DB::table('password_histories')->where('user_id', $user->id)->orderByDesc('id')->limit(self::HISTORY)->pluck('password') as $old) {
            if (Hash::check($plain, $old)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Saves a password the person chose: the old one goes to the history, the
     * "must change" flag is cleared, the change is logged.
     */
    public static function save(object $user, string $plain, string $channel = 'web', ?\Illuminate\Http\Request $request = null): void
    {
        $old = DB::table('users')->where('id', $user->id)->value('password');
        DB::transaction(function () use ($user, $plain, $old) {
            if ($old) {
                DB::table('password_histories')->insert(['user_id' => $user->id, 'password' => $old, 'created_at' => now()]);
                $keep = DB::table('password_histories')->where('user_id', $user->id)->orderByDesc('id')->limit(self::HISTORY)->pluck('id');
                DB::table('password_histories')->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
            }
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($plain), 'must_change_password' => false, 'password_changed_at' => now(), 'updated_at' => now(),
            ]);
        });
        LoginSecurity::event($user, $user->email, $channel, 'password_changed', $request);
    }
}
