<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\MobileDeviceToken;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserLoginSession;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Token;

/**
 * Auth endpoints for the mobile admin app (Flutter).
 *
 * Unlike the SPA — which signs in through the web session and rides the
 * laravel_token cookie — the app authenticates every call with a Passport
 * personal access token issued here and sent as a Bearer header. That
 * token flows through the same auth:api guard and token.timeout
 * bookkeeping as the SPA, so mobile devices appear in the login-activity
 * report and can be revoked from Security Settings.
 */
class MobileAuthController extends BaseController
{
    use ScopesToCompany;

    /**
     * Public handshake used to validate a server URL typed into the app,
     * and to negotiate versions before login. Deliberately exposes only
     * data that is already public on the login page.
     */
    public function ping(Request $request)
    {
        $settings = Setting::forCompany($this->resolveActingCompanyId($request));
        $helpers = new helpers;

        // Admin-configured mobile settings (System Settings → Mobile App).
        // Each falls back to the general setting when left empty.
        $mobileEnabled = (bool) ($settings->mobile_app_enabled ?? true);
        $minVersion = $settings->mobile_min_version
            ?: (string) config('mobile.min_app_version');

        return response()->json([
            'ok' => true,
            'server' => 'stocky',
            'api_version' => (int) config('mobile.api_version'),
            'min_app_version' => $minVersion,
            'app_name' => $settings->mobile_app_name
                ?: ($settings->app_name ?? config('app.name')),
            'logo' => $settings->mobile_logo ?: ($settings->logo ?? null),
            'default_language' => $settings->default_language ?? 'en',
            'currency' => [
                'code' => $helpers->Get_Currency_Code(),
                'symbol' => $helpers->Get_Currency(),
            ],
            // The app disables itself (and shows the message) when off.
            'mobile_enabled' => $mobileEnabled,
            'maintenance_message' => $settings->mobile_maintenance_message ?? null,
            'branding' => [
                'primary_color' => $settings->mobile_primary_color ?: null,
                'theme_mode' => $settings->mobile_theme_mode ?: 'system',
            ],
            'features' => [
                'offline' => (bool) ($settings->mobile_offline_enabled ?? true),
                'scanner' => (bool) ($settings->mobile_scanner_enabled ?? true),
                'price_edit' => (bool) ($settings->mobile_allow_price_edit ?? true),
            ],
            'modules' => ($settings && $settings->mobile_modules)
                ? json_decode($settings->mobile_modules, true)
                : null,
            'support' => [
                'phone' => $settings->mobile_support_phone ?: null,
                'email' => $settings->mobile_support_email ?: null,
            ],
        ]);
    }

    // --------------- Login (issues a Bearer token) ----------------\\

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:191',
        ]);

        // Credentials are checked directly against the user row instead of
        // Auth::attempt(): the api middleware group has no session store
        // for the session guard to write to.
        $user = User::where('email', $request->email)
            ->whereNull('deleted_at')
            // Staff app only: customers sign in to the Client Portal.
            ->where('type', '!=', 'customer')
            ->first();

        // Login security (2026-10-27, App\Support\LoginSecurity): locked = refused before
        // the password; wrong ones count towards the lock.
        if ($user && ($until = \App\Support\LoginSecurity::lockedUntil($user))) {
            \App\Support\LoginSecurity::event($user, $request->email, 'mobile', 'blocked', $request, 'locked');

            return response()->json(['status' => 'Locked', 'message' => \App\Support\LoginSecurity::lockedMessage($until)], 423);
        }
        if (! $user || ! Hash::check($request->password, $user->password)) {
            $locked = \App\Support\LoginSecurity::recordFailure($user ? [$user] : [], (string) $request->email, $request, 'mobile');

            return response()->json([
                'status' => $locked ? 'Locked' : false,
                'message' => $locked ? \App\Support\LoginSecurity::lockedMessage($locked) : 'Incorrect Login',
            ], $locked ? 423 : 401);
        }
        \App\Support\LoginSecurity::clearFailures($user);

        if (! (bool) $user->statut) {
            return response()->json([
                'status' => 'NotActive',
                'message' => 'This user not active',
            ], 403);
        }

        // System Settings → Mobile App can switch the app off for everyone.
        $settings = Setting::forCompany($this->resolveActingCompanyId($request));
        if (! (bool) ($settings->mobile_app_enabled ?? true)) {
            return response()->json([
                'status' => 'AppDisabled',
                'message' => $settings->mobile_maintenance_message
                    ?: 'The mobile app is currently unavailable. Please contact your administrator.',
            ], 403);
        }

        $deviceName = $request->input('device_name') ?: 'Mobile App';
        // Two-step code / new password first (2026-10-27): the answer carries a login_token for
        // mobile/login/verify | resend | password. An app without those screens shows the message.
        if ($steps = \App\Support\LoginSecurity::pendingSteps($user, $request, (string) $request->password)) {
            $token = \Illuminate\Support\Str::random(48);
            $this->putPending($token, ['user_id' => $user->id, 'steps' => $steps, 'device' => $deviceName, 'code' => null, 'code_exp' => 0, 'tries' => 0, 'sent_at' => 0]);

            return $this->stepResponse($request, $token, $user);
        }

        return $this->issueToken($request, $user, $deviceName);
    }

    // --------------- Login steps (two-step code, new password) ----------------\\

    /** POST mobile/login/verify {login_token, code} */
    public function loginVerify(Request $request)
    {
        [$p, $user, $token] = $this->pending($request);
        if (! $user) {
            return $this->expired();
        }
        $code = trim((string) $request->input('code'));
        $digits = preg_replace('/\s+/', '', $code);
        $ok = \App\Support\LoginSecurity::method($user) === 'app'
            ? \App\Support\LoginSecurity::verifyAppCode($user, $code)
            : ($p['code'] && $p['code_exp'] >= time() && preg_match('/^\d{6}$/', $digits) && Hash::check($digits, $p['code']));
        if (! $ok) {
            \App\Support\LoginSecurity::event($user, $user->email, 'mobile', 'code_failed', $request);
            $locked = \App\Support\LoginSecurity::recordFailure([$user->fresh()], (string) $user->email, $request, 'mobile');
            $p['tries']++;
            if ($locked || $p['tries'] >= \App\Support\LoginSecurity::CODE_TRIES) {
                \Illuminate\Support\Facades\Cache::forget('mobile_login:'.hash('sha256', $token));

                return response()->json(['status' => 'Restart', 'message' => $locked ? \App\Support\LoginSecurity::lockedMessage($locked) : 'Too many wrong codes. Sign in again.'], 423);
            }
            $this->putPending($token, $p);

            return response()->json(['status' => 'WrongCode', 'message' => 'Wrong or expired code.'], 422);
        }
        \App\Support\LoginSecurity::event($user, $user->email, 'mobile', 'code_ok', $request);
        $p['steps'] = array_values(array_diff($p['steps'], ['2fa']));

        return $this->nextStep($request, $token, $p, $user);
    }

    /** POST mobile/login/resend {login_token} -- another emailed code (once a minute). */
    public function loginResend(Request $request)
    {
        [$p, $user, $token] = $this->pending($request);
        if (! $user) {
            return $this->expired();
        }
        if ($p['sent_at'] && time() - $p['sent_at'] < 60) {
            return response()->json(['status' => 'Wait', 'message' => 'Wait a minute before asking for another code.'], 429);
        }
        [$sent, $hash] = \App\Support\LoginSecurity::sendEmailCode($user, $request, 'mobile');
        $this->putPending($token, array_merge($p, ['code' => $hash, 'code_exp' => time() + \App\Support\LoginSecurity::CODE_MINUTES * 60, 'tries' => 0, 'sent_at' => time()]));

        return response()->json(['status' => $sent, 'message' => $sent ? 'We sent you a new code.' : 'The code could not be sent.'], $sent ? 200 : 500);
    }

    /** POST mobile/login/password {login_token, password, password_confirmation} */
    public function loginPassword(Request $request)
    {
        [$p, $user, $token] = $this->pending($request);
        if (! $user) {
            return $this->expired();
        }
        $request->validate(['password' => \App\Support\PasswordPolicy::rules($user)]);
        \App\Support\PasswordPolicy::save($user, (string) $request->input('password'), 'mobile', $request);
        $p['steps'] = array_values(array_diff($p['steps'], ['password']));

        return $this->nextStep($request, $token, $p, $user->fresh());
    }

    private function nextStep(Request $request, string $token, array $p, User $user)
    {
        if (! $p['steps']) {
            \Illuminate\Support\Facades\Cache::forget('mobile_login:'.hash('sha256', $token));

            return $this->issueToken($request, $user, $p['device']);
        }
        $this->putPending($token, $p);

        return $this->stepResponse($request, $token, $user);
    }

    /** 403 {status: TwoFactorRequired | PasswordChangeRequired, step, method, email, login_token, message}; the 2fa step emails its code. */
    private function stepResponse(Request $request, string $token, User $user)
    {
        $p = \Illuminate\Support\Facades\Cache::get('mobile_login:'.hash('sha256', $token));
        $step = $p['steps'][0];
        $method = \App\Support\LoginSecurity::method($user);
        if ($step === '2fa' && $method === 'email' && (! $p['code'] || $p['code_exp'] < time())) {
            [, $hash] = \App\Support\LoginSecurity::sendEmailCode($user, $request, 'mobile');
            $this->putPending($token, array_merge($p, ['code' => $hash, 'code_exp' => time() + \App\Support\LoginSecurity::CODE_MINUTES * 60, 'tries' => 0, 'sent_at' => time()]));
        }

        return response()->json([
            'status' => $step === 'password' ? 'PasswordChangeRequired' : 'TwoFactorRequired',
            'step' => $step,
            'method' => $method,
            'login_token' => $token,
            'message' => $step === 'password'
                ? 'You are using a standard password: choose your own to continue.'
                : ($method === 'app' ? 'Enter the code of your authenticator app.' : 'We emailed you a 6-digit code.'),
        ], 403);
    }

    private function issueToken(Request $request, User $user, string $deviceName)
    {
        $accessToken = $user->createToken($deviceName)->accessToken;
        \App\Support\LoginSecurity::event($user, $user->email, 'mobile', 'success', $request, $deviceName);

        return response()->json([
            'status' => true,
            'token_type' => 'Bearer',
            'access_token' => $accessToken,
            'user' => $this->userPayload($user),
            'permissions' => $user->roles()->first()?->permissions->pluck('name') ?? [],
        ]);
    }

    private function putPending(string $token, array $p): void
    {
        \Illuminate\Support\Facades\Cache::put('mobile_login:'.hash('sha256', $token), $p, now()->addMinutes(15));
    }

    /** @return array{0: ?array, 1: ?User, 2: string} */
    private function pending(Request $request): array
    {
        $token = (string) $request->input('login_token');
        $p = strlen($token) === 48 ? \Illuminate\Support\Facades\Cache::get('mobile_login:'.hash('sha256', $token)) : null;
        $user = $p ? User::whereKey($p['user_id'])->whereNull('deleted_at')->where('statut', 1)->first() : null;
        if ($user && \App\Support\LoginSecurity::lockedUntil($user)) {
            $user = null;
        }

        return [$p, $user, $token];
    }

    private function expired()
    {
        return response()->json(['status' => 'Restart', 'message' => 'The sign-in expired. Enter your email and password again.'], 419);
    }

    // --------------- Current user (app refresh) ----------------\\

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'status' => true,
            'user' => $this->userPayload($user),
            'permissions' => $user->roles()->first()?->permissions->pluck('name') ?? [],
        ]);
    }

    // --------------- Logout (revokes this device's token) ----------------\\

    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user->token();

        if ($token instanceof Token) {
            // Mark the session revoked first so the device list stays in
            // sync even if the Passport revoke below were to fail.
            try {
                UserLoginSession::where('access_token_id', (string) $token->getKey())
                    ->update(['revoked_at' => now()]);
            } catch (\Throwable $e) {
                // best-effort bookkeeping
            }

            $token->revoke();
        }

        // Forget this device's push registration when the app sends it.
        if ($request->filled('device_token')) {
            MobileDeviceToken::where('user_id', $user->id)
                ->where('token', $request->input('device_token'))
                ->delete();
        }

        return response()->json(['status' => true]);
    }

    /** Profile + display settings the app needs at boot; mirrors GetUserAuth. */
    private function userPayload(User $user): array
    {
        // A mobile user has no company-selector concept -- their own
        // company_id is authoritative, unlike the SPA's SuperAdmin picker.
        $settings = Setting::forCompany($user->company_id);
        $helpers = new helpers;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'app_name' => $settings->app_name ?? config('app.name'),
            'logo' => $settings->logo ?? null,
            'company' => $settings->CompanyName ?? '',
            'default_language' => $settings->default_language ?? 'en',
            'currency' => [
                'code' => $helpers->Get_Currency_Code(),
                'symbol' => $helpers->Get_Currency(),
            ],
            'date_format' => $settings->date_format ?? 'YYYY-MM-DD',
            'price_format' => $settings->price_format ?? null,
            'price_decimals' => (bool) ($settings->enable_3_decimal_pricing ?? false) ? 3 : 2,
            'timezone' => env('APP_TIMEZONE', 'UTC'),
            // Admin module toggles ({key: bool}, null = everything enabled) —
            // the app gates its navigation with these plus the permissions list.
            'module_flags' => ($settings && $settings->module_flags)
                ? json_decode($settings->module_flags, true)
                : null,
            // Mobile-only module switches from System Settings → Mobile App.
            'mobile_modules' => ($settings && $settings->mobile_modules)
                ? json_decode($settings->mobile_modules, true)
                : null,
            'mobile_features' => [
                'offline' => (bool) ($settings->mobile_offline_enabled ?? true),
                'scanner' => (bool) ($settings->mobile_scanner_enabled ?? true),
                'price_edit' => (bool) ($settings->mobile_allow_price_edit ?? true),
            ],
            'mobile_primary_color' => $settings->mobile_primary_color ?: null,
            'mobile_theme_mode' => $settings->mobile_theme_mode ?: 'system',
        ];
    }
}
