<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends BaseController
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    /**
     * Display the form to request a password reset link.
     */
    public function showLinkRequestForm()
    {
        return view('auth.passwords.email');
    }

    /**
     * Handle sending a reset link to the given email.
     */
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|string|max:190']);

        // Set mail configuration from database before sending email
        $this->Set_config_mail();

        // The USERNAME (user's rule, 2026-11-22): one person may have a different password in each company, so the link
        // is for that one login only; it goes to the login's email. Never says which companies hold that email.
        $typed = trim((string) $request->input('email'));
        $user = \App\Models\User::whereNull('deleted_at')->where('type', '!=', 'customer')->where('username', $typed)->first();
        if (! $user || ! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors(['email' => __(Password::INVALID_USER)]);
        }
        $broker = Password::broker();
        $user->sendPasswordResetNotification($broker->createToken($user));
        $status = Password::RESET_LINK_SENT;

        return back()->with(['status' => __($status)]);
    }
}
