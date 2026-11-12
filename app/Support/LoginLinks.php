<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sign-in links (user's request, 2026-11-12): for staff who use WhatsApp but not passwords (Turismo's pilots and
 * hostesses). The company's admin makes a link in Company logins and sends it by WhatsApp; the person taps it and is in.
 *
 * Safety:
 *  - the link works on ONE device only: the first one that opens it claims it (its device key, the HttpOnly cookie
 *    LoginSecurity::DEVICE_COOKIE, is bound to the link); any other device is refused and the attempt is logged;
 *  - a link nobody opened stops working after UNUSED_DAYS; the admin can make a new one (the old one dies) or remove it;
 *  - only staff logins (employee, sales agent, supplier): never an admin, a SuperAdmin or a customer;
 *  - a disabled or deleted login, or a locked one, can't enter; everything goes to login_events;
 *  - no password and no two-step code are asked: the bound device is the second factor.
 */
class LoginLinks
{
    public const UNUSED_DAYS = 7;

    public const TYPES = ['employee', 'sales_agent', 'supplier'];

    public static function allowedFor(object $user): bool
    {
        return in_array((string) $user->type, self::TYPES, true);
    }

    /** A new link for this login (any previous one stops working). Returns the link's address. */
    public static function create(object $user, ?object $by): string
    {
        $code = Str::random(32);
        DB::transaction(function () use ($user, $by, $code) {
            DB::table('login_links')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('login_links')->insert([
                'company_id' => $user->company_id, 'user_id' => $user->id,
                'token_hash' => hash('sha256', $code), 'token_enc' => Crypt::encryptString($code),
                'expires_at' => now()->addDays(self::UNUSED_DAYS), 'created_by' => $by->id ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return self::url((int) $user->company_id, $code);
    }

    public static function revoke(int $userId): int
    {
        return DB::table('login_links')->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public static function url(int $companyId, string $code): string
    {
        return rtrim(PublicUrl::base($companyId), '/').'/acceso/'.$code;
    }

    /** user id => the live link's state, for Company logins. */
    public static function states(array $userIds): array
    {
        $out = [];
        foreach (DB::table('login_links')->whereIn('user_id', $userIds ?: [0])->whereNull('revoked_at')->get() as $l) {
            try {
                $url = self::url((int) $l->company_id, Crypt::decryptString($l->token_enc));
            } catch (\Throwable $e) {
                $url = null;   // APP_KEY changed: the admin makes a new one
            }
            $expired = ! $l->device_hash && $l->expires_at && now()->greaterThan($l->expires_at);
            $out[$l->user_id] = [
                'url' => $url,
                'state' => $l->device_hash ? 'bound' : ($expired ? 'expired' : 'pending'),
                'device' => $l->device_name, 'claimed_at' => $l->claimed_at, 'last_used_at' => $l->last_used_at,
                'uses' => (int) $l->uses, 'expires_at' => $l->expires_at, 'created_at' => $l->created_at,
            ];
        }

        return $out;
    }

    /**
     * Opening /acceso/{code}: [user, null] to sign in, or [null|user, reason] to refuse
     * (unknown, revoked, expired, other_device, not_allowed, disabled, locked).
     */
    public static function check(string $code, Request $request): array
    {
        if (! preg_match('/^[A-Za-z0-9]{32}$/', $code)) {
            return [null, 'unknown'];
        }
        $link = DB::table('login_links')->where('token_hash', hash('sha256', $code))->first();
        if (! $link) {
            return [null, 'unknown'];
        }
        $user = \App\Models\User::whereKey($link->user_id)->where('company_id', $link->company_id)->whereNull('deleted_at')->first();
        if (! $user) {
            return [null, 'unknown'];
        }
        if ($link->revoked_at) {
            return [$user, 'revoked'];
        }
        if (! self::allowedFor($user)) {
            return [$user, 'not_allowed'];
        }
        if ((int) $user->statut !== 1) {
            return [$user, 'disabled'];
        }
        if (! empty($user->locked_until) && now()->lessThan($user->locked_until)) {
            return [$user, 'locked'];
        }

        $key = (string) $request->cookie(LoginSecurity::DEVICE_COOKIE, '');
        if ($link->device_hash) {
            if (strlen($key) < 40 || ! hash_equals($link->device_hash, hash('sha256', $key))) {
                return [$user, 'other_device'];
            }
            DB::table('login_links')->where('id', $link->id)->update(['last_used_at' => now(), 'uses' => $link->uses + 1, 'updated_at' => now()]);

            return [$user, null];
        }

        // First opening: this device claims the link (only if nobody else did in between).
        if ($link->expires_at && now()->greaterThan($link->expires_at)) {
            return [$user, 'expired'];
        }
        if (strlen($key) < 40) {
            $key = Str::random(64);
            Cookie::queue(Cookie::make(LoginSecurity::DEVICE_COOKIE, $key, 60 * 24 * 365 * 5, '/', null, $request->isSecure(), true, false, 'lax'));
            // LoginSecurity::signedIn() runs next and reads this cookie from the request: give it the same key,
            // or it would make (and send) another one and the link would no longer match this device.
            $request->cookies->set(LoginSecurity::DEVICE_COOKIE, $key);
        }
        $claimed = DB::table('login_links')->where('id', $link->id)->whereNull('device_hash')->update([
            'device_hash' => hash('sha256', $key), 'device_name' => mb_substr(NetworkAccess::deviceName((string) $request->userAgent()), 0, 120),
            'claimed_ip' => $request->ip(), 'claimed_at' => now(), 'last_used_at' => now(), 'uses' => 1, 'updated_at' => now(),
        ]);

        return $claimed ? [$user, null] : [$user, 'other_device'];
    }
}
