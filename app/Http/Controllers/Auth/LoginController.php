<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Support\LoginSecurity;
use App\Support\WebLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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
            'email' => ['required', 'string', 'max:190'], // the field keeps its name: it takes the username (or an enabled email)
            'password' => ['required', 'string'],
        ]);

        $remember = (bool) $request->input('remember', false);
        $typed = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        // Sign in with the USERNAME (user's rule, 2026-11-22): unique in all of Murano, while an email can repeat across
        // companies that never know each other. The email works only where its IT Admin / the SuperAdmin enabled it and it
        // doesn't repeat (App\Support\LoginLookup). Login security (2026-10-27): a locked login is refused before its
        // password is checked; wrong passwords count towards the lock (5 in an hour = locked for an hour).
        $user = \App\Support\LoginLookup::find($typed, 'staff');
        if ($user && ($until = LoginSecurity::lockedUntil($user))) {
            LoginSecurity::event($user, $typed, 'web', 'blocked', $request, 'locked');

            return $this->failed($request, LoginSecurity::lockedMessage($until));
        }
        if (! $user || ! $user->password || ! Hash::check($password, $user->password) || (int) $user->statut !== 1) {
            $locked = LoginSecurity::recordFailure($user ? [$user] : [], $typed, $request, 'web');

            return $this->failed($request, $locked ? LoginSecurity::lockedMessage($locked) : 'These credentials do not match our records.');
        }
        LoginSecurity::clearFailures($user);

        return $this->afterPassword($request, $user, $password, $remember);
    }
    /** The password was right for this login: network security, its pending steps, else in. */
    private function afterPassword(Request $request, \App\Models\User $user, string $password, bool $remember)
    {
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
        $steps = LoginSecurity::pendingSteps($user, $request, $password);
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
