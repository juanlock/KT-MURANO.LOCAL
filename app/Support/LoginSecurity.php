<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Login security (user's rules, 2026-10-27), for every door into Murano -- the staff
 * login (/login), the customer portal and the old api/getAccessToken (the phone app's door was removed, 2026-11-10):
 *
 *  - LOCKOUT: 5 wrong passwords in an hour lock that login for 1 hour, from any IP
 *    (the per-IP throttle stays too). The user and the company's admins get an email;
 *    an admin unlocks it in Settings -> Login security (or Users).
 *  - STANDARD PASSWORD: whoever signs in with a standard password (CompanyLogins
 *    PASSWORDS / ACCESS_PASSWORD, or the company's default password of their type)
 *    must change it before entering -- except the demos' people (demo_records),
 *    which keep them on purpose so every role can be tested.
 *  - TWO-STEP VERIFICATION: required for the user types each company ticks
 *    (settings.security_2fa_types; the SuperAdmin follows AdminSystem's), or by the
 *    user's own choice (Profile). A 6-digit code by email, or the authenticator app
 *    when the user set one up (+ recovery codes). "Remember this computer" = 30 days.
 *    Never the demos' people (their emails can't receive codes).
 *  - SIGN-IN ALERTS: a sign-in from a browser this login never used -> email to the
 *    user (company switch, default on).
 * Every attempt goes to login_events. Password rules: App\Support\PasswordPolicy.
 */
class LoginSecurity
{
    public const MAX_FAILS = 5;

    public const LOCK_MINUTES = 60;

    public const CODE_MINUTES = 10;

    public const CODE_TRIES = 5;

    public const REMEMBER_DAYS = 30;

    public const DEVICE_COOKIE = 'murano_login_device';

    /** User types a company can require the two-step code for (+ SuperAdmin on AdminSystem). */
    public const TYPES = ['admin', 'employee', 'customer', 'supplier', 'sales_agent'];

    // ------------------------------------------------------------- lockout

    public static function lockedUntil(object $user): ?Carbon
    {
        $until = $user->locked_until ?? null;

        return $until && Carbon::parse($until)->isFuture() ? Carbon::parse($until) : null;
    }

    /**
     * A wrong password for these logins (the rows with the email typed). Returns the
     * time the login is locked until when this failure locked it.
     */
    public static function recordFailure(iterable $users, string $email, Request $request, string $channel): ?Carbon
    {
        $lockedNow = null;
        $any = false;
        foreach ($users as $u) {
            $any = true;
            $recent = $u->last_failed_at && Carbon::parse($u->last_failed_at)->gt(now()->subMinutes(self::LOCK_MINUTES));
            $fails = $recent ? ((int) $u->failed_logins + 1) : 1;
            if ($fails >= self::MAX_FAILS) {
                $until = now()->addMinutes(self::LOCK_MINUTES);
                DB::table('users')->where('id', $u->id)->update(['failed_logins' => 0, 'last_failed_at' => now(), 'locked_until' => $until]);
                self::event($u, $email, $channel, 'locked', $request, self::MAX_FAILS.' wrong passwords');
                self::notifyLocked($u, $request, $until);
                $lockedNow = $until;
            } else {
                DB::table('users')->where('id', $u->id)->update(['failed_logins' => $fails, 'last_failed_at' => now()]);
                self::event($u, $email, $channel, 'failed', $request, "$fails/".self::MAX_FAILS);
            }
        }
        if (! $any) {
            self::event(null, $email, $channel, 'failed', $request, 'unknown email');
        }

        return $lockedNow;
    }

    /** The password was right: the failure count starts again. */
    public static function clearFailures(object $user): void
    {
        if ((int) ($user->failed_logins ?? 0) || ($user->locked_until ?? null) || ($user->last_failed_at ?? null)) {
            DB::table('users')->where('id', $user->id)->update(['failed_logins' => 0, 'last_failed_at' => null, 'locked_until' => null]);
        }
    }

    public static function unlock(int $userId, ?object $by, ?Request $request = null): void
    {
        DB::table('users')->where('id', $userId)->update(['failed_logins' => 0, 'last_failed_at' => null, 'locked_until' => null]);
        $u = DB::table('users')->where('id', $userId)->first();
        if ($u) {
            self::event($u, $u->email, 'web', 'unlocked', $request, $by ? 'by '.trim(($by->firstname ?? '').' '.($by->lastname ?? '')) : null);
        }
    }

    public static function lockedMessage(Carbon $until, ?string $lang = null): string
    {
        $es = self::es($lang);
        $mins = max(1, (int) ceil(now()->diffInSeconds($until, true) / 60));

        return $es
            ? "Por seguridad esta cuenta está bloqueada por demasiados intentos fallidos. Intente de nuevo en $mins min, restablezca su contraseña o pida a un administrador que la desbloquee."
            : "For security this account is locked after too many failed attempts. Try again in $mins min, reset your password, or ask an administrator to unlock it.";
    }

    // ---------------------------------------------------- standard password

    public static function isDemo(object $user): bool
    {
        return DB::table('demo_records')->where('record_type', 'users')->where('record_id', $user->id)->exists();
    }

    /** The plain password typed is one of the standard ones (constants or the company's default for the type). */
    public static function isStandardPassword(object $user, string $plain): bool
    {
        $std = array_merge(array_values(CompanyLogins::PASSWORDS), [CompanyLogins::ACCESS_PASSWORD]);
        if (in_array($plain, $std, true)) {
            return true;
        }
        $key = ['employee' => 'employee', 'customer' => 'customer', 'supplier' => 'supplier', 'sales_agent' => 'sales_agent', 'online_store' => 'online_store'][strtolower((string) $user->type)] ?? null;
        if (! $key || ! $user->company_id) {
            return false;
        }
        $hash = DB::table('settings')->where('company_id', $user->company_id)->whereNull('deleted_at')->value('default_password_'.$key);

        return $hash && Hash::check($plain, $hash);
    }

    public static function mustChangePassword(object $user, ?string $plain = null): bool
    {
        if (self::isDemo($user)) {
            return false;
        }

        return (bool) ($user->must_change_password ?? false) || ($plain !== null && self::isStandardPassword($user, $plain));
    }

    // -------------------------------------------------------------- 2-step

    /** @return array{types: array<string>, alerts: bool} */
    public static function settings(int $companyId): array
    {
        $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['security_2fa_types', 'security_login_alerts']);
        $types = json_decode((string) ($s->security_2fa_types ?? ''), true);

        return ['types' => is_array($types) ? array_values($types) : [], 'alerts' => (bool) ($s->security_login_alerts ?? true)];
    }

    public static function companyOf(object $user): int
    {
        return $user->type === 'SuperAdmin' ? \App\Models\Setting::TEMPLATE_COMPANY_ID : (int) $user->company_id;
    }

    public static function twoFactorRequired(object $user): bool
    {
        if (self::isDemo($user)) {
            return false;
        }
        if (($user->two_factor_confirmed_at ?? null) || ($user->two_factor_email ?? false)) {
            return true; // the user's own choice (Profile)
        }
        $type = $user->type === 'SuperAdmin' ? 'SuperAdmin' : strtolower((string) $user->type);

        return in_array($type, self::settings(self::companyOf($user))['types'], true);
    }

    /** 'app' when the user set up an authenticator, else 'email'. */
    public static function method(object $user): string
    {
        return ($user->two_factor_confirmed_at ?? null) && ($user->two_factor_secret ?? null) ? 'app' : 'email';
    }

    /** What is still missing before this login can enter: ['2fa'?, 'password'?]. */
    public static function pendingSteps(object $user, Request $request, ?string $plain): array
    {
        $steps = [];
        if (self::twoFactorRequired($user) && ! self::rememberedDevice($user, $request)) {
            $steps[] = '2fa';
        }
        if (self::mustChangePassword($user, $plain)) {
            $steps[] = 'password';
            if (! ($user->must_change_password ?? false)) {
                DB::table('users')->where('id', $user->id)->update(['must_change_password' => true]);
            }
        }

        return $steps;
    }

    /** Email a new code. @return array{0: bool, 1: string} [sent, code hash] */
    public static function sendEmailCode(object $user, Request $request, string $channel): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $es = self::es(self::langOf($user));
        $html = self::layout($es ? 'Su código de verificación' : 'Your verification code',
            '<p>'.($es ? 'Use este código para terminar de ingresar a Murano:' : 'Use this code to finish signing in to Murano:').'</p>'
            .'<p style="font-size:30px;font-weight:800;letter-spacing:8px;margin:18px 0;color:#03387b">'.$code.'</p>'
            .'<p style="color:#5b6b82">'.($es ? 'Vence en '.self::CODE_MINUTES.' minutos. Si no fue usted, cambie su contraseña.' : 'It expires in '.self::CODE_MINUTES.' minutes. If it wasn\'t you, change your password.').'</p>'
            .self::where($request, $es));
        $sent = self::mail(self::companyOf($user), (string) $user->email, ($es ? 'Código de verificación: ' : 'Verification code: ').$code, $html);
        self::event($user, $user->email, $channel, 'code_sent', $request, $sent ? null : 'mail failed');

        return [$sent, Hash::make($code)];
    }

    /** An authenticator code (±30 s) or one of the recovery codes (used once). */
    public static function verifyAppCode(object $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{6}$/', $code) && ($user->two_factor_secret ?? null)) {
            $secret = Crypt::decryptString($user->two_factor_secret);
            $slice = intdiv(time(), 30);
            foreach ([-1, 0, 1] as $w) {
                if (hash_equals(self::totp($secret, $slice + $w), $code)) {
                    return true;
                }
            }

            return false;
        }

        return self::useRecoveryCode($user, $code);
    }

    public static function useRecoveryCode(object $user, string $code): bool
    {
        $codes = json_decode((string) ($user->two_factor_recovery ? Crypt::decryptString($user->two_factor_recovery) : '[]'), true) ?: [];
        $h = hash('sha256', strtolower(str_replace([' ', '-'], '', $code)));
        $i = array_search($h, $codes, true);
        if ($i === false) {
            return false;
        }
        unset($codes[$i]);
        DB::table('users')->where('id', $user->id)->update(['two_factor_recovery' => Crypt::encryptString(json_encode(array_values($codes)))]);

        return true;
    }

    /** @return array<string> 8 new recovery codes (shown once; only their hashes are kept). */
    public static function newRecoveryCodes(int $userId): array
    {
        $plain = [];
        for ($i = 0; $i < 8; $i++) {
            $plain[] = strtolower(Str::random(4).'-'.Str::random(4));
        }
        DB::table('users')->where('id', $userId)->update([
            'two_factor_recovery' => Crypt::encryptString(json_encode(array_map(fn ($c) => hash('sha256', str_replace('-', '', $c)), $plain))),
        ]);

        return $plain;
    }

    public static function newSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function otpauthUri(object $user, string $secret): string
    {
        $issuer = 'Murano';
        $label = rawurlencode($issuer.':'.$user->email);

        return "otpauth://totp/$label?secret=$secret&issuer=".rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    /** RFC 6238 code of a base32 secret for a 30-second slice. */
    public static function totp(string $secret, int $slice): string
    {
        $key = self::unbase32($secret);
        $hash = hash_hmac('sha1', pack('N*', 0).pack('N*', $slice), $key, true);
        $o = ord($hash[19]) & 0xF;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32(string $bin): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function unbase32(string $b32): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $p = strpos($alpha, $c);
            if ($p !== false) {
                $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    // ------------------------------------------------- devices and alerts

    public static function rememberedDevice(object $user, Request $request): bool
    {
        $row = self::deviceRow($user, $request);

        return $row && $row->remember_until && Carbon::parse($row->remember_until)->isFuture();
    }

    private static function deviceRow(object $user, Request $request): ?object
    {
        $key = (string) $request->cookie(self::DEVICE_COOKIE, '');

        return strlen($key) >= 40
            ? DB::table('login_devices')->where('user_id', $user->id)->where('token_hash', hash('sha256', $key))->first()
            : null;
    }

    /**
     * The sign-in finished on this browser: remember it (and the two-step code for
     * 30 days when asked). A browser this login never used -> sign-in alert.
     */
    public static function signedIn(object $user, Request $request, string $channel, bool $remember2fa = false): void
    {
        self::clearFailures($user);
        $key = (string) $request->cookie(self::DEVICE_COOKIE, '');
        if (strlen($key) < 40) {
            $key = Str::random(64);
            Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $key, 60 * 24 * 365 * 5, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        $hash = hash('sha256', $key);
        $row = DB::table('login_devices')->where('user_id', $user->id)->where('token_hash', $hash)->first();
        $set = ['last_ip' => $request->ip(), 'last_seen_at' => now(), 'updated_at' => now()] + ($remember2fa ? ['remember_until' => now()->addDays(self::REMEMBER_DAYS)] : []);
        if ($row) {
            DB::table('login_devices')->where('id', $row->id)->update($set);
        } else {
            DB::table('login_devices')->insert($set + ['user_id' => $user->id, 'token_hash' => $hash, 'name' => NetworkAccess::deviceName((string) $request->userAgent()), 'created_at' => now()]);
            // The very first sign-in we record for this login is not "new" (nothing to compare with).
            $known = DB::table('login_devices')->where('user_id', $user->id)->count() > 1;
            if ($known) {
                self::event($user, $user->email, $channel, 'new_device', $request);
                self::alertNewDevice($user, $request);
            }
        }
        self::event($user, $user->email, $channel, 'success', $request);
    }

    public static function alertNewDevice(object $user, Request $request): void
    {
        if (self::isDemo($user) || ! self::settings(self::companyOf($user))['alerts']) {
            return;
        }
        $es = self::es(self::langOf($user));
        $html = self::layout($es ? 'Nuevo ingreso a su cuenta' : 'New sign-in to your account',
            '<p>'.($es ? 'Alguien ingresó a su cuenta de Murano desde un equipo o navegador que no había usado antes:' : 'Someone signed in to your Murano account from a computer or browser not used before:').'</p>'
            .self::where($request, $es)
            .'<p>'.($es ? 'Si fue usted, no tiene que hacer nada. Si no fue usted, <b>cambie su contraseña ya</b> y avise a su administrador.' : 'If it was you, there is nothing to do. If it wasn\'t you, <b>change your password now</b> and tell your administrator.').'</p>');
        self::mail(self::companyOf($user), (string) $user->email, $es ? 'Nuevo ingreso a su cuenta de Murano' : 'New sign-in to your Murano account', $html);
    }

    private static function notifyLocked(object $user, Request $request, Carbon $until): void
    {
        $cid = self::companyOf($user);
        $es = self::es(self::langOf($user));
        $who = trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: $user->email;
        $body = '<p>'.($es
                ? "La cuenta <b>".e($who).'</b> ('.e($user->email).') se bloqueó por '.self::MAX_FAILS.' contraseñas incorrectas seguidas. Se desbloquea sola a las <b>'.$until->format('H:i').'</b>.'
                : 'The account <b>'.e($who).'</b> ('.e($user->email).') was locked after '.self::MAX_FAILS.' wrong passwords in a row. It unlocks by itself at <b>'.$until->format('H:i').'</b>.').'</p>'
            .self::where($request, $es)
            .'<p>'.($es ? 'Si no fue usted, alguien está intentando entrar: cambie su contraseña. Un administrador puede desbloquearla antes en Ajustes → Seguridad de acceso.' : 'If it wasn\'t you, someone is trying to get in: change your password. An administrator can unlock it sooner in Settings → Login security.').'</p>';
        $html = self::layout($es ? 'Cuenta bloqueada' : 'Account locked', $body);
        $subject = $es ? 'Cuenta bloqueada: '.$who : 'Account locked: '.$who;
        $to = array_unique(array_merge([(string) $user->email], $cid ? CompanyMail::adminEmails($cid) : []));
        foreach ($to as $email) {
            self::mail($cid, $email, $subject, $html);
        }
    }

    // ---------------------------------------------------------------- misc

    public static function event(?object $user, ?string $email, string $channel, string $event, ?Request $request = null, ?string $note = null): void
    {
        try {
            DB::table('login_events')->insert([
                'user_id' => $user->id ?? null,
                'company_id' => $user->company_id ?? null,
                'email' => $email ? mb_substr($email, 0, 191) : null,
                'channel' => $channel, 'event' => $event,
                'ip' => $request?->ip(),
                'device' => $request ? mb_substr(NetworkAccess::deviceName((string) $request->userAgent()), 0, 120) : null,
                'note' => $note ? mb_substr($note, 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // never break a sign-in over the log
        }
    }

    /** Send through the company's mail server; never to reserved test addresses. */
    public static function mail(int $companyId, string $to, string $subject, string $html): bool
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL) || CompanyMail::reserved($to)) {
            return false;
        }
        try {
            [$mailer] = CompanyMail::mailer($companyId ?: \App\Models\Setting::TEMPLATE_COMPANY_ID);
            $mailer->html($html, fn ($m) => $m->to($to)->subject($subject));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function langOf(object $user): string
    {
        return (string) ($user->preferred_locale ?: (DB::table('settings')->where('company_id', self::companyOf($user))->whereNull('deleted_at')->value('default_language') ?: 'en'));
    }

    public static function es(?string $lang = null): bool
    {
        return str_starts_with((string) ($lang ?? app()->getLocale()), 'es');
    }

    /** "When / where / browser" lines for the emails. */
    private static function where(Request $request, bool $es): string
    {
        return '<table style="margin:12px 0;font-size:14px;color:#0f2747">'
            .'<tr><td style="padding:2px 12px 2px 0;color:#5b6b82">'.($es ? 'Cuándo' : 'When').'</td><td>'.now()->format('Y-m-d H:i').' ('.config('app.timezone').')</td></tr>'
            .'<tr><td style="padding:2px 12px 2px 0;color:#5b6b82">IP</td><td>'.e((string) $request->ip()).'</td></tr>'
            .'<tr><td style="padding:2px 12px 2px 0;color:#5b6b82">'.($es ? 'Equipo' : 'Device').'</td><td>'.e(NetworkAccess::deviceName((string) $request->userAgent())).'</td></tr>'
            .'</table>';
    }

    private static function layout(string $title, string $body): string
    {
        return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#0f2747">'
            .'<h2 style="color:#03387b;margin:0 0 12px">'.e($title).'</h2>'.$body
            .'<p style="color:#94a3b8;font-size:12px;margin-top:24px">Murano · '.e((string) config('app.url')).'</p></div>';
    }
}
