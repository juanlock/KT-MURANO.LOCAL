<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * SuperAdmin -> "enter as a company's admin" (user's request, 2026-10-20): after
 * signing in, a SuperAdmin picks the dashboard or a company admin to work as
 * (LoginChooseController). The session becomes that admin's; this signed,
 * HttpOnly cookie remembers WHICH SuperAdmin did it, so:
 *  - Network security never blocks it (the SuperAdmin is exempt, NetworkAccess::check);
 *  - the header offers "Back to SuperAdmin" (UserController::GetUserAuth -> superadmin_switch).
 * Read on 'web' and 'api' requests, so it is not encrypted (EncryptCookies::$except)
 * but HMAC-signed with the app key: value = target.super.expires.hmac.
 */
class SuperAdminSwitch
{
    public const COOKIE = 'murano_sa_switch';

    private const HOURS = 12;

    /** Queue the cookie: $super is now working as $target. */
    public static function start(User $super, User $target): void
    {
        $exp = now()->addHours(self::HOURS)->timestamp;
        $payload = $target->id.'.'.$super->id.'.'.$exp;
        Cookie::queue(Cookie::make(self::COOKIE, $payload.'.'.self::sign($payload), self::HOURS * 60, '/', null, null, true, false, 'lax'));
    }

    public static function stop(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** The SuperAdmin working as $user in this browser, or null. */
    public static function superAdminFor(?object $user, Request $request): ?User
    {
        $raw = (string) $request->cookie(self::COOKIE, '');
        if (! $user || ! preg_match('/^(\d+)\.(\d+)\.(\d+)\.([a-f0-9]{64})$/', $raw, $m)) {
            return null;
        }
        [, $target, $super, $exp, $sig] = $m;
        if ((int) $target !== (int) $user->id || (int) $exp < time()
            || ! hash_equals(self::sign("$target.$super.$exp"), $sig)) {
            return null;
        }
        $sa = User::whereKey((int) $super)->whereNull('deleted_at')->where('statut', 1)->first();

        return $sa && $sa->type === 'SuperAdmin' ? $sa : null;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'sa-switch|'.$payload, (string) config('app.key'));
    }
}
