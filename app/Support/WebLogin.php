<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserLoginSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The end of a staff sign-in (/login), shared by LoginController (no extra step
 * needed) and LoginStepsController (after the two-step code / the new password,
 * 2026-10-27): the session starts, it is recorded for Login devices, the browser
 * is remembered (sign-in alert when new), and the SuperAdmin goes to the chooser.
 */
class WebLogin
{
    /** Session key of a sign-in waiting for its steps (LoginStepsController). */
    public const PENDING = 'login.pending';

    public static function complete(Request $request, User $user, bool $remember, bool $remember2fa = false)
    {
        Auth::guard('web')->login($user, $remember);

        // Regenerate the web session ID to prevent fixation
        $request->session()->regenerate();

        // Persist the current web session ID in user_login_sessions
        try {
            $sessionId = $request->session()->getId();
            UserLoginSession::query()->updateOrCreate(
                ['user_id' => (int) $user->id, 'session_id' => $sessionId],
                [
                    'access_token_id' => $sessionId, // marker for web sessions
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) ($request->userAgent() ?? ''),
                    'logged_in_at' => now(),
                    'last_activity_at' => now(),
                    'revoked_at' => null,
                ]
            );
        } catch (\Throwable $e) {
            // Never break login if tracking fails
        }
        LoginSecurity::signedIn($user, $request, 'web', $remember2fa);

        // A fresh sign-in is never "working as a company admin" any more.
        SuperAdminSwitch::stop();
        // SuperAdmin (2026-10-20): first the chooser -- dashboard, or enter as a
        // company's admin (LoginChooseController). The intended URL is kept for
        // "Go to the dashboard".
        if ($user->type === 'SuperAdmin' && ! ($request->expectsJson() || $request->ajax())) {
            return redirect()->route('login.choose');
        }

        return redirect()->intended(\App\Providers\RouteServiceProvider::HOME);
    }

    /** Start the extra steps of a sign-in (two-step code, new password) and go to the first one. */
    public static function begin(Request $request, User $user, bool $remember, array $steps)
    {
        $request->session()->put(self::PENDING, [
            'user_id' => $user->id, 'remember' => $remember, 'steps' => $steps, 'at' => time(),
            'code' => null, 'code_exp' => null, 'tries' => 0, 'sent_at' => null, 'remember_device' => false,
        ]);
        $url = route($steps[0] === '2fa' ? 'login.verify' : 'login.password');
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['code' => 'login_step', 'step' => $steps[0], 'redirect' => $url]);
        }

        return redirect($url);
    }
}
