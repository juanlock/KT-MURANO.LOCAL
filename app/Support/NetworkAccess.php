<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Network security (2026-10-16): when a company turns it on (Settings ->
 * Network security), its staff can use the admin panel only
 *   - from one of the company's networks (an IP or a range, e.g. 203.0.113.0/24), or
 *   - from a computer an admin approved (a random key in the browser cookie
 *     "murano_device"; only its hash is stored, table trusted_devices).
 * Checked at sign-in (LoginController) and on every staff request (Is_Active).
 * Never applies to: the SuperAdmin, customers, suppliers, and anything outside
 * the admin panel (store, websites, portfolio, online booking, portals have
 * their own sign-in). A user is restricted by the company default unless
 * Users -> Edit sets "restricted" / "anywhere" for them.
 */
class NetworkAccess
{
    public const COOKIE = 'murano_device';

    private static array $cache = [];

    /** The company's settings row bits (cached per request). */
    public static function company(int $companyId): array
    {
        if (! isset(self::$cache[$companyId])) {
            $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')
                ->first(['network_security_enabled', 'network_allowed', 'network_default_restricted']);
            self::$cache[$companyId] = [
                'enabled' => (bool) ($s->network_security_enabled ?? false),
                'allowed' => self::cleanList(json_decode((string) ($s->network_allowed ?? '[]'), true) ?: []),
                'default_restricted' => (bool) ($s->network_default_restricted ?? true),
            ];
        }

        return self::$cache[$companyId];
    }

    public static function forget(int $companyId): void
    {
        unset(self::$cache[$companyId]);
    }

    /** [{ip, label}] with only valid IPs / ranges. */
    public static function cleanList(array $list): array
    {
        $out = [];
        foreach ($list as $row) {
            $ip = trim((string) (is_array($row) ? ($row['ip'] ?? '') : $row));
            if ($ip !== '' && self::validIpOrRange($ip)) {
                $out[] = ['ip' => $ip, 'label' => mb_substr(trim((string) (is_array($row) ? ($row['label'] ?? '') : '')), 0, 80)];
            }
        }

        return $out;
    }

    public static function validIpOrRange(string $ip): bool
    {
        [$addr, $bits] = array_pad(explode('/', $ip, 2), 2, null);
        if (! filter_var($addr, FILTER_VALIDATE_IP)) return false;
        if ($bits === null) return true;
        $max = str_contains($addr, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
    }

    /** Is this login subject to the company's network rules? */
    public static function restricts(object $user): bool
    {
        if (in_array($user->type ?? '', ['SuperAdmin', 'customer', 'supplier'], true)) return false;
        $c = self::company((int) ($user->company_id ?? 0));
        if (! $c['enabled']) return false;
        $own = $user->network_access ?? null;
        if ($own === 'anywhere') return false;
        if ($own === 'restricted') return true;

        return $c['default_restricted'];
    }

    public static function ipAllowed(int $companyId, string $ip): bool
    {
        $list = array_column(self::company($companyId)['allowed'], 'ip');

        return $list && IpUtils::checkIp($ip, $list);
    }

    /** The approved device row of this browser for this user, if any. */
    public static function approvedDevice(object $user, Request $request): ?object
    {
        $key = (string) $request->cookie(self::COOKIE, '');
        if (strlen($key) < 40) return null;

        return DB::table('trusted_devices')->where('user_id', $user->id)->where('token_hash', hash('sha256', $key))
            ->where('status', 'approved')->first();
    }

    /**
     * null = allowed; else the reason ('network' -- not on a company network and
     * this computer isn't approved).
     */
    public static function check(object $user, Request $request): ?string
    {
        if (! self::restricts($user)) return null;
        if (self::ipAllowed((int) $user->company_id, (string) $request->ip())) return null;
        $device = self::approvedDevice($user, $request);
        if ($device) {
            if (! $device->last_used_at || now()->diffInMinutes($device->last_used_at, true) >= 10) {
                DB::table('trusted_devices')->where('id', $device->id)->update(['last_used_at' => now(), 'last_ip' => $request->ip()]);
            }

            return null;
        }

        return 'network';
    }

    /**
     * A blocked sign-in asks for this computer to be approved: reuses this
     * browser's key (or makes one, queued as a 5-year cookie) and records / keeps
     * a pending request for the user. Returns the device row.
     */
    public static function requestApproval(object $user, Request $request): object
    {
        $key = (string) $request->cookie(self::COOKIE, '');
        if (strlen($key) < 40) {
            $key = Str::random(64);
            Cookie::queue(Cookie::make(self::COOKIE, $key, 60 * 24 * 365 * 5, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        $hash = hash('sha256', $key);
        $row = DB::table('trusted_devices')->where('user_id', $user->id)->where('token_hash', $hash)->first();
        if (! $row) {
            DB::table('trusted_devices')->insert([
                'company_id' => (int) $user->company_id, 'user_id' => $user->id, 'token_hash' => $hash,
                'name' => self::deviceName((string) $request->userAgent()), 'status' => 'pending',
                'requested_ip' => $request->ip(), 'last_ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('trusted_devices')->where('user_id', $user->id)->where('token_hash', $hash)->first();
        } elseif ($row->status !== 'approved') {
            // A removed computer asking again is a new request.
            DB::table('trusted_devices')->where('id', $row->id)->update(['status' => 'pending', 'last_ip' => $request->ip(), 'updated_at' => now()]);
            $row->status = 'pending';
        }

        return $row;
    }

    /** "Chrome on Windows" from a user agent. */
    public static function deviceName(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone / iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown system',
        };

        return "$browser on $os";
    }

    /**
     * The message staff see when they are blocked: at sign-in the computer was
     * just sent for approval; in the middle of a session it is sent when they
     * sign in again.
     */
    public static function blockedMessage(bool $sent = true): string
    {
        return $sent
            ? 'You can\'t use Murano from this network. This computer was sent to your administrator for approval -- try again once it is approved, or connect from the office network.'
            : 'You can\'t use Murano from this network. Sign in again to send this computer to your administrator for approval, or connect from the office network.';
    }
}
