<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Company admin -> "Open" a login of the company (user's rule, 2026-10-25,
 * Company logins in the profile menu). The browser's session becomes that
 * login's; this signed, HttpOnly cookie remembers WHICH admin did it, so the
 * header offers "Back to admin" (UserController::GetUserAuth -> admin_switch).
 * Same design as SuperAdminSwitch: read on 'web' and 'api' requests, so not
 * encrypted (EncryptCookies::$except) but HMAC-signed with the app key.
 */
class AdminSwitch
{
    public const COOKIE = 'murano_admin_as';

    private const HOURS = 8;

    public static function start(User $admin, User $target): void
    {
        $exp = now()->addHours(self::HOURS)->timestamp;
        $payload = $target->id.'.'.$admin->id.'.'.$exp;
        Cookie::queue(Cookie::make(self::COOKIE, $payload.'.'.self::sign($payload), self::HOURS * 60, '/', null, null, true, false, 'lax'));
    }

    public static function stop(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** The company admin working as $user in this browser, or null. */
    public static function adminFor(?object $user, Request $request): ?User
    {
        $raw = (string) $request->cookie(self::COOKIE, '');
        if (! $user || ! preg_match('/^(\d+)\.(\d+)\.(\d+)\.([a-f0-9]{64})$/', $raw, $m)) {
            return null;
        }
        [, $target, $admin, $exp, $sig] = $m;
        if ((int) $target !== (int) $user->id || (int) $exp < time()
            || ! hash_equals(self::sign("$target.$admin.$exp"), $sig)) {
            return null;
        }
        $a = User::whereKey((int) $admin)->whereNull('deleted_at')->where('statut', 1)->first();

        return $a && $a->type === 'admin' && (int) $a->company_id === (int) $user->company_id ? $a : null;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'admin-switch|'.$payload, (string) config('app.key'));
    }
}
