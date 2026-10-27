<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Support\LoginSecurity;
use App\Support\WebLogin;
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
        $email = (string) $request->input('email');

        // Login security (2026-10-27, App\Support\LoginSecurity): a locked login is
        // refused before its password is even checked; wrong passwords count
        // towards the lock (5 in an hour = locked for an hour, from any IP).
        $logins = \App\Models\User::where('email', $email)->where('type', '!=', 'customer')->whereNull('deleted_at')->get();
        foreach ($logins as $login) {
            if ($until = LoginSecurity::lockedUntil($login)) {
                LoginSecurity::event($login, $email, 'web', 'blocked', $request, 'locked');

                return $this->failed($request, LoginSecurity::lockedMessage($until));
            }
        }
        $guard = Auth::guard('web');
        if (! $guard->validate($this->credentials($request))) {
            $locked = LoginSecurity::recordFailure($logins, $email, $request, 'web');

            return $this->failed($request, $locked ? LoginSecurity::lockedMessage($locked) : 'These credentials do not match our records.');
        }
        $user = $guard->getLastAttempted();
        LoginSecurity::clearFailures($user);

        // Network security (2026-10-16): a restricted user outside the
        // company's networks, on a computer not approved yet, is refused and
        // this computer is sent to the admins for approval.
        if (\App\Support\NetworkAccess::check($user, $request)) {
            \App\Support\NetworkAccess::requestApproval($user, $request);
            $message = \App\Support\NetworkAccess::blockedMessage();
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $message, 'code' => 'network_blocked'], 403);
            }

            return back()->withErrors(['email' => $message])->withInput($request->only('email', 'remember'));
        }

        // Two-step code and / or a new password first (LoginStepsController), else straight in.
        $steps = LoginSecurity::pendingSteps($user, $request, (string) $request->input('password'));
        if ($steps) {
            return WebLogin::begin($request, $user, $remember, $steps);
        }

        return WebLogin::complete($request, $user, $remember);
    }

    private function failed(Request $request, string $message)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withErrors(['email' => $message])->withInput($request->only('email', 'remember'));
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
