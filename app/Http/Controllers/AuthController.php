<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends BaseController
{
    // --------------- Function Login ----------------\\

    public function getAccessToken(Request $request)
    {
        $request->validate([
            'email' => 'required',
            'password' => 'required',
        ]);

        $credentials = request(['email', 'password']);
        // Staff app only: customers use the Client Portal; no deleted users.
        $credentials[] = fn ($query) => $query->where('type', '!=', 'customer')->whereNull('deleted_at');

        // Login security (2026-10-27): this old token door obeys the same rules as /login --
        // locked logins refused, wrong passwords counted, and no token while a two-step
        // code or a new password is pending (those are done on the web login).
        // Sign in with the username, or an email enabled for it (App\Support\LoginLookup, user's rule 2026-11-22).
        $candidate = \App\Support\LoginLookup::find((string) $request->email, 'staff');
        if ($candidate && ($until = \App\Support\LoginSecurity::lockedUntil($candidate))) {
            \App\Support\LoginSecurity::event($candidate, $request->email, 'api', 'blocked', $request, 'locked');

            return response()->json(['message' => \App\Support\LoginSecurity::lockedMessage($until), 'status' => false], 423);
        }
        if (! $candidate || ! $candidate->password || ! \Illuminate\Support\Facades\Hash::check((string) $request->password, $candidate->password)) {
            \App\Support\LoginSecurity::recordFailure($candidate ? [$candidate] : [], (string) $request->email, $request, 'api');

            return response()->json(['message' => __('Incorrect Login'), 'status' => false]);
        }

        \App\Support\LoginSecurity::clearFailures($candidate);
        if (\App\Support\LoginSecurity::pendingSteps($candidate, $request, (string) $request->password)) {
            return response()->json(['message' => __('Sign in on the web login to finish the security steps.'), 'status' => false], 403);
        }
        if ((int) $candidate->statut === 0) {
            return response()->json(['message' => __('This user not active'), 'status' => 'NotActive']);
        }
        Auth::login($candidate);
        $user = auth()->user();
        $tokenResult = $user->createToken('Access Token');
        $token = $tokenResult->token;
        $this->setCookie('Murano_token', $tokenResult->accessToken);

        return response()->json([
            'Murano_token' => $tokenResult->accessToken,
            'username' => Auth::User()->username,
            'status' => true,
        ]);
    }

    // --------------- Function Logout ----------------\\

    public function logout()
    {
        if (Auth::check()) {
            $user = Auth::user()->token();
            $user->revoke();
            $this->destroyCookie('Murano_token');

            return response()->json('success');
        }

    }
}
