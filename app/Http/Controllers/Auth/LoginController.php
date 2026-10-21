<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserLoginSession;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */

    /**
     * Get the needed authorization credentials from the request.
     *
     * @return array
     */
    protected function credentials(\Illuminate\Http\Request $request)
    {
        return [
            'email' => $request->{$this->username()},
            'password' => $request->password,
            'statut' => 1,
            // Staff app only: customers sign in to the Client Portal
            // (/portal) with their users row; deleted users can't sign in.
            fn ($query) => $query->where('type', '!=', 'customer')->whereNull('deleted_at'),
        ];
    }

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function showLoginForm()
    {
        return response()
            ->view('auth.login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Handle a login request to the application.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = (bool) $request->input('remember', false);

        if (Auth::attempt($this->credentials($request), $remember)) {
            // Network security (2026-10-16): a restricted user outside the
            // company's networks, on a computer not approved yet, is signed out
            // again and this computer is sent to the admins for approval.
            $signedIn = Auth::guard('web')->user();
            if ($signedIn && \App\Support\NetworkAccess::check($signedIn, $request)) {
                \App\Support\NetworkAccess::requestApproval($signedIn, $request);
                Auth::guard('web')->logout();
                $message = \App\Support\NetworkAccess::blockedMessage();
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['message' => $message, 'code' => 'network_blocked'], 403);
                }

                return back()->withErrors(['email' => $message])->withInput($request->only('email', 'remember'));
            }

            // Regenerate the web session ID to prevent fixation
            $request->session()->regenerate();

            // Persist the current web session ID in user_login_sessions
            try {
                $user = Auth::guard('web')->user();
                if ($user) {
                    $sessionId = $request->session()->getId();

                    UserLoginSession::query()->updateOrCreate(
                        [
                            'user_id' => (int) $user->id,
                            'session_id' => $sessionId,
                        ],
                        [
                            'access_token_id' => $sessionId, // marker for web sessions
                            'ip_address' => $request->ip(),
                            'user_agent' => (string) ($request->userAgent() ?? ''),
                            'logged_in_at' => now(),
                            'last_activity_at' => now(),
                            'revoked_at' => null,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                // Never break login if tracking fails
            }

            // A fresh sign-in is never "working as a company admin" any more.
            \App\Support\SuperAdminSwitch::stop();
            // SuperAdmin (2026-10-20): first the chooser -- dashboard, or enter
            // as a company's admin (LoginChooseController). The intended URL is
            // kept for "Go to the dashboard".
            if ($signedIn && $signedIn->type === 'SuperAdmin' && ! ($request->expectsJson() || $request->ajax())) {
                return redirect()->route('login.choose');
            }

            return redirect()->intended($this->redirectTo);
        }

        // Failed login
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        return back()->withErrors([
            'email' => 'These credentials do not match our records.',
        ])->withInput($request->only('email', 'remember'));
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request)
    {
        // 1) Explicitly log out the web guard
        $user = Auth::guard('web')->user();

        // 0) A cashier with an open POS register signs out only after closing
        //    it (close-out with counted cash -> admins' email): the POS
        //    "Close register & sign out" closes first, then calls this.
        $openRegister = $user ? \App\Models\CashRegister::where('user_id', $user->id)->where('status', 'open')
            ->orderByDesc('id')->first(['id', 'warehouse_id']) : null;
        if ($openRegister) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'code' => 'register_open',
                    'message' => 'Close register #'.$openRegister->id.' before signing out.',
                    'register' => $openRegister,
                    'user_id' => $user->id,
                ], 409);
            }

            return redirect('/next/pos');
        }

        if ($user) {
            // Clear remember token to avoid automatic re-authentication
            $user->setRememberToken(null);
            $user->save();
        }

        Auth::guard('web')->logout();
        // Signing out also ends "SuperAdmin working as a company admin".
        \App\Support\SuperAdminSwitch::stop();

        // 2) Fully invalidate the web session
        //    - clears all session data
        //    - regenerates the session ID
        $request->session()->invalidate();

        // 3) Regenerate CSRF token for the new empty session
        $request->session()->regenerateToken();

        // 4) For SPA (AJAX) logout calls, return JSON and let the frontend
        //    perform a full-page navigation with window.location.replace('/login')
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true]);
        }

        // 5) For classic form logouts, redirect to /login
        return redirect()->route('login');
    }
    
    
    

    /**
     * Get the login username to be used by the controller.
     */
    public function username()
    {
        return 'email';
    }
}
