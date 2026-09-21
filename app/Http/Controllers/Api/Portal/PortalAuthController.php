<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPortalLocale;
use App\Models\Client;
use App\Models\PortalClient;
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

        $portalClient = PortalClient::where('email', $cred['email'])->first();

        if (!$portalClient || !Hash::check($cred['password'], $portalClient->password)) {
            return response()->json(['message' => __('portal.invalid_credentials')], 401);
        }

        if ((int) $portalClient->status !== 1) {
            return response()->json(['message' => __('portal.portal_disabled_account')], 403);
        }

        if (Auth::guard('portal')->attempt($cred, $request->boolean('remember'))) {
            // Do not regenerate session here: the app and portal share the same session cookie.
            // Regenerating would replace the session id and log out the main app (auth:web) user.

            $this->rememberSessionLocale($portalClient);

            return response()->json([
                'success' => true,
                'portal_client' => $this->portalClientResponse($portalClient),
            ]);
        }

        return response()->json(['message' => __('portal.invalid_credentials')], 401);
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

        if ((int) $portalClient->status !== 1) {
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
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $portalClient = PortalClient::where('invitation_token', $data['token'])->first();

        if (!$portalClient || !$portalClient->invitation_sent_at || $portalClient->invitation_sent_at->addHours(48)->isPast()) {
            return response()->json(['message' => __('portal.invite_link_expired')], 400);
        }

        $portalClient->update([
            'password' => Hash::make($data['password']),
            'status' => 1,
            'invitation_token' => null,
            'invitation_sent_at' => null,
        ]);

        Auth::guard('portal')->login($portalClient);
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
        ];
    }
}
