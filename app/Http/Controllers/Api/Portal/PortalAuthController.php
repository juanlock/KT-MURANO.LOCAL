<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPortalLocale;
use App\Models\Client;
use App\Models\PortalClient;
use App\Support\LoginSecurity;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PortalAuthController extends Controller
{
    /**
     * POST /api/portal/login - Client portal login.
     */
    public function login(Request $request)
    {
        $cred = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Portal login = the customer's own users row (PortalClient scopes to
        // type=customer, not deleted). users.email isn't DB-unique, so prefer
        // the row that actually has the portal on.
        $portalClient = PortalClient::where('email', $cred['email'])->orderByDesc('portal_enabled')->first();

        // Login security (2026-10-27): locked = refused before the password is checked;
        // wrong passwords count towards the lock (App\Support\LoginSecurity).
        if ($portalClient && ($until = LoginSecurity::lockedUntil($portalClient))) {
            LoginSecurity::event($portalClient, $cred['email'], 'portal', 'blocked', $request, 'locked');

            return response()->json(['message' => LoginSecurity::lockedMessage($until)], 423);
        }
        if (!$portalClient || !Hash::check($cred['password'], $portalClient->password)) {
            $locked = LoginSecurity::recordFailure($portalClient ? [$portalClient] : [], $cred['email'], $request, 'portal');

            return response()->json(['message' => $locked ? LoginSecurity::lockedMessage($locked) : __('portal.invalid_credentials')], $locked ? 423 : 401);
        }
        LoginSecurity::clearFailures($portalClient);

        if (!$portalClient->canAccessPortal() || !$portalClient->client) {
            return response()->json(['message' => __('portal.portal_disabled_account')], 403);
        }

        // Two-step code and / or a new password first (loginVerify / loginPassword).
        $steps = LoginSecurity::pendingSteps($portalClient, $request, $cred['password']);
        if ($steps) {
            $request->session()->put(self::PENDING, [
                'user_id' => $portalClient->id, 'remember' => $request->boolean('remember'), 'steps' => $steps, 'at' => time(),
                'code' => null, 'code_exp' => null, 'tries' => 0, 'sent_at' => null, 'remember_device' => false,
            ]);

            return response()->json($this->stepResponse($request, $portalClient));
        }

        return $this->finishLogin($request, $portalClient, $request->boolean('remember'));
    }

    /** Session key of a portal sign-in waiting for its steps. */
    private const PENDING = 'portal.login.pending';

    /**
     * POST /api/portal/login/verify {code, remember_device} -- the two-step code
     * (emailed, or the authenticator app / a recovery code).
     */
    public function loginVerify(Request $request)
    {
        [$p, $client] = $this->pendingLogin($request);
        if (! $client) {
            return response()->json(['message' => __('portal.sec_expired'), 'code' => 'expired'], 419);
        }
        $code = trim((string) $request->input('code'));
        $digits = preg_replace('/\s+/', '', $code);
        $ok = LoginSecurity::method($client) === 'app'
            ? LoginSecurity::verifyAppCode($client, $code)
            : ($p['code'] && $p['code_exp'] >= time() && preg_match('/^\d{6}$/', $digits) && Hash::check($digits, $p['code']));
        if (! $ok) {
            LoginSecurity::event($client, $client->email, 'portal', 'code_failed', $request);
            $locked = LoginSecurity::recordFailure([$client->fresh()], (string) $client->email, $request, 'portal');
            $tries = $p['tries'] + 1;
            if ($locked || $tries >= LoginSecurity::CODE_TRIES) {
                $request->session()->forget(self::PENDING);

                return response()->json(['message' => $locked ? LoginSecurity::lockedMessage($locked) : __('portal.sec_too_many'), 'code' => 'restart'], 423);
            }
            $request->session()->put(self::PENDING, array_merge($p, ['tries' => $tries]));

            return response()->json(['message' => __('portal.sec_wrong_code')], 422);
        }
        LoginSecurity::event($client, $client->email, 'portal', 'code_ok', $request);
        $p = array_merge($p, ['steps' => array_values(array_diff($p['steps'], ['2fa'])), 'remember_device' => $request->boolean('remember_device'), 'code' => null]);
        $request->session()->put(self::PENDING, $p);

        return $this->nextStep($request, $p, $client);
    }

    /** POST /api/portal/login/resend -- another emailed code (once a minute). */
    public function loginResend(Request $request)
    {
        [$p, $client] = $this->pendingLogin($request);
        if (! $client) {
            return response()->json(['message' => __('portal.sec_expired'), 'code' => 'expired'], 419);
        }
        if ($p['sent_at'] && time() - $p['sent_at'] < 60) {
            return response()->json(['message' => __('portal.sec_wait')], 429);
        }
        [$sent, $hash] = LoginSecurity::sendEmailCode($client, $request, 'portal');
        $request->session()->put(self::PENDING, array_merge($p, ['code' => $hash, 'code_exp' => time() + LoginSecurity::CODE_MINUTES * 60, 'tries' => 0, 'sent_at' => time()]));

        return response()->json(['success' => $sent, 'message' => $sent ? __('portal.sec_resent') : __('portal.sec_send_failed')], $sent ? 200 : 500);
    }

    /** POST /api/portal/login/password {password, password_confirmation} -- a new password instead of a standard one. */
    public function loginPassword(Request $request)
    {
        [$p, $client] = $this->pendingLogin($request);
        if (! $client) {
            return response()->json(['message' => __('portal.sec_expired'), 'code' => 'expired'], 419);
        }
        $request->validate(['password' => PasswordPolicy::rules($client)]);
        PasswordPolicy::save($client, (string) $request->input('password'), 'portal', $request);
        $p = array_merge($p, ['steps' => array_values(array_diff($p['steps'], ['password']))]);
        $request->session()->put(self::PENDING, $p);

        return $this->nextStep($request, $p, $client->fresh());
    }

    /** @return array{0: ?array, 1: ?PortalClient} */
    private function pendingLogin(Request $request): array
    {
        $p = $request->session()->get(self::PENDING);
        if (! $p || time() - ($p['at'] ?? 0) > 900) {
            $request->session()->forget(self::PENDING);

            return [null, null];
        }
        $client = PortalClient::whereKey($p['user_id'])->first();
        if (! $client || LoginSecurity::lockedUntil($client) || ! $client->canAccessPortal()) {
            $request->session()->forget(self::PENDING);

            return [null, null];
        }

        return [$p, $client];
    }

    private function nextStep(Request $request, array $p, PortalClient $client)
    {
        if (! $p['steps']) {
            $request->session()->forget(self::PENDING);

            return $this->finishLogin($request, $client, (bool) $p['remember'], (bool) $p['remember_device']);
        }

        return response()->json($this->stepResponse($request, $client));
    }

    /** {step: 2fa|password, method, email} -- the 2fa step emails its code when shown. */
    private function stepResponse(Request $request, PortalClient $client): array
    {
        $p = $request->session()->get(self::PENDING);
        $step = $p['steps'][0];
        $method = LoginSecurity::method($client);
        $sent = true;
        if ($step === '2fa' && $method === 'email' && (! $p['code'] || $p['code_exp'] < time())) {
            [$sent, $hash] = LoginSecurity::sendEmailCode($client, $request, 'portal');
            $request->session()->put(self::PENDING, array_merge($p, ['code' => $hash, 'code_exp' => time() + LoginSecurity::CODE_MINUTES * 60, 'tries' => 0, 'sent_at' => time()]));
        }
        [$name, $domain] = array_pad(explode('@', (string) $client->email, 2), 2, '');

        return ['step' => $step, 'method' => $method, 'email' => mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain]
            + ($sent ? [] : ['warning' => __('portal.sec_send_failed')]);
    }

    private function finishLogin(Request $request, PortalClient $portalClient, bool $remember, bool $remember2fa = false)
    {
        // Log in the exact row verified above (attempt() would re-look-up by
        // email and could pick a different same-email customer).
        // Do not regenerate session here: the app and portal share the same session cookie.
        // Regenerating would replace the session id and log out the main app (auth:web) user.
        Auth::guard('portal')->login($portalClient, $remember);
        LoginSecurity::signedIn($portalClient, $request, 'portal', $remember2fa);

        $this->rememberSessionLocale($portalClient);

        return response()->json([
            'success' => true,
            'portal_client' => $this->portalClientResponse($portalClient),
        ]);
    }

    /**
     * POST /api/portal/logout - Only clear portal auth, do not invalidate entire session.
     */
    public function logout(Request $request)
    {
        Auth::guard('portal')->logout();
        // Do not call session()->invalidate() so admin/other guards in same session are untouched

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/portal/me - Current authenticated portal client (for session check).
     */
    public function me(Request $request)
    {
        $portalClient = Auth::guard('portal')->user();

        if (!$portalClient) {
            return response()->json(null, 401);
        }

        $portalClient->load('client');

        if (!$portalClient->canAccessPortal() || !$portalClient->client) {
            Auth::guard('portal')->logout();
            // Only clear portal auth, do not invalidate entire session

            return response()->json(['message' => __('portal.portal_disabled')], 403);
        }

        return response()->json([
            'portal_client' => $this->portalClientResponse($portalClient),
        ]);
    }

    /**
     * GET /api/portal/accept-invite - Validate invitation token (for set-password page).
     */
    public function validateInviteToken(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return response()->json(['valid' => false, 'message' => __('portal.invite_link_invalid')], 400);
        }

        $portalClient = PortalClient::where('invitation_token', $token)->first();

        if (!$portalClient || !$portalClient->invitation_sent_at || $portalClient->invitation_sent_at->addHours(48)->isPast()) {
            return response()->json(['valid' => false, 'message' => __('portal.invite_link_expired')], 400);
        }

        return response()->json([
            'valid' => true,
            'email' => $portalClient->email,
        ]);
    }

    /**
     * POST /api/portal/set-password - Set password from invitation.
     */
    public function setPassword(Request $request)
    {
        $request->validate(['token' => ['required', 'string']]);
        $portalClient = PortalClient::where('invitation_token', $request->input('token'))->first();

        if (!$portalClient || !$portalClient->invitation_sent_at || $portalClient->invitation_sent_at->addHours(48)->isPast()) {
            return response()->json(['message' => __('portal.invite_link_expired')], 400);
        }
        // Password rules (2026-10-27): App\Support\PasswordPolicy.
        $data = $request->validate(['password' => PasswordPolicy::rules($portalClient)]);

        PasswordPolicy::save($portalClient, $data['password'], 'portal', $request);
        $portalClient->update([
            'status' => 1,
            'invitation_token' => null,
            'invitation_sent_at' => null,
        ]);

        Auth::guard('portal')->login($portalClient);
        LoginSecurity::signedIn($portalClient, $request, 'portal');
        // Do not regenerate session so the main app (auth:web) user is not logged out.

        $this->rememberSessionLocale($portalClient);

        return response()->json([
            'success' => true,
            'portal_client' => $this->portalClientResponse($portalClient),
        ]);
    }

    /**
     * A language picked on the login / set-password page (session) becomes the
     * client's saved preference — it is what they were looking at when they
     * signed in. With no explicit choice this session, the saved preference
     * (or cookie / app default) keeps applying via SetPortalLocale.
     */
    private function rememberSessionLocale(PortalClient $portalClient): void
    {
        $chosen = session(SetPortalLocale::SESSION_KEY);

        if ($chosen && isset(SetLocale::SUPPORTED[$chosen]) && $portalClient->preferred_locale !== $chosen) {
            $portalClient->forceFill(['preferred_locale' => $chosen])->save();
        }
    }

    private function portalClientResponse(PortalClient $portalClient): array
    {
        $portalClient->load('client');

        return [
            'id' => $portalClient->id,
            'email' => $portalClient->email,
            'client_id' => $portalClient->client_id,
            'locale' => $portalClient->preferred_locale ?: app()->getLocale(),
            'client' => [
                'id' => $portalClient->client->id,
                'name' => $portalClient->client->name,
                'email' => $portalClient->client->email,
                'phone' => $portalClient->client->phone,
                'adresse' => $portalClient->client->adresse,
            ],
            // Business Modules of the customer's company (portal nav hides what's off).
            'modules' => [
                'helpdesk' => \App\Support\ModuleFlags::enabled((int) $portalClient->client->company_id, 'helpdesk'),
                // "My hospital" only for a customer who is a patient there (2026-10-25).
                'hospital' => \App\Support\ModuleFlags::enabled((int) $portalClient->client->company_id, 'hospital')
                    && \Illuminate\Support\Facades\DB::table('patients')->where('client_id', $portalClient->client_id)->whereNull('deleted_at')->exists(),
            ],
        ];
    }
}
