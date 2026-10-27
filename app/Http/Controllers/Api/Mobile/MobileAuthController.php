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
        // The app has no screen for the two-step code or a new password: those
        // sign-ins are finished once on the web (Murano's login page) first.
        if ($steps = \App\Support\LoginSecurity::pendingSteps($user, $request, (string) $request->password)) {
            return response()->json([
                'status' => in_array('password', $steps, true) ? 'PasswordChangeRequired' : 'TwoFactorRequired',
                'message' => in_array('password', $steps, true)
                    ? 'You are using a standard password: sign in to Murano on the web once and choose your own.'
                    : 'Your account uses two-step verification, which the app does not support yet: sign in on the web.',
            ], 403);
        }

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
