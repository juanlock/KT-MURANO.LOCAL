<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends BaseController
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Display the password reset view for the given token.
     */
    public function showResetForm(Request $request, $token = null)
    {
        return view('auth.passwords.reset')->with([
            'token' => $token,
            'email' => $request->email,
        ]);
    }

    /**
     * Handle an incoming new password request.
     */
    public function reset(Request $request)
    {
        // Password rules + history (App\Support\PasswordPolicy, 2026-10-27), checked
        // against the login the email belongs to.
        $target = \App\Models\User::where('email', (string) $request->email)->whereNull('deleted_at')->first();
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => \App\Support\PasswordPolicy::rules($target),
        ]);

        // Set mail configuration from database before sending email (if password reset success email is sent)
        $this->Set_config_mail();

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) use ($request) {
                \App\Support\PasswordPolicy::save($user, $password, 'web', $request);
                // A reset also unlocks the login (LoginSecurity lockout).
                \App\Support\LoginSecurity::unlock((int) $user->id, null, $request);
                $user->forceFill(['remember_token' => Str::random(60)])->save();

                event(new PasswordReset($user));
            }
        );

        return $status == Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => [__($status)]]);
    }
}
