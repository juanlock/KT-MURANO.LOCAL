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
        $logins = \App\Models\User::where('email', (string) $request->email)->where('type', '!=', 'customer')->whereNull('deleted_at')->get();
        foreach ($logins as $login) {
            if ($until = \App\Support\LoginSecurity::lockedUntil($login)) {
                \App\Support\LoginSecurity::event($login, $request->email, 'api', 'blocked', $request, 'locked');

                return response()->json(['message' => \App\Support\LoginSecurity::lockedMessage($until), 'status' => false], 423);
            }
        }
        if (! Auth::guard('web')->validate($credentials)) {
            \App\Support\LoginSecurity::recordFailure($logins, (string) $request->email, $request, 'api');

            return response()->json(['message' => 'Incorrect Login', 'status' => false]);
        }
        $candidate = Auth::guard('web')->getLastAttempted();
        \App\Support\LoginSecurity::clearFailures($candidate);
        if (\App\Support\LoginSecurity::pendingSteps($candidate, $request, (string) $request->password)) {
            return response()->json(['message' => 'Sign in on the web login to finish the security steps.', 'status' => false], 403);
        }

        if (Auth::attempt($credentials)) {
            $userStatus = Auth::User()->statut;
            if ($userStatus === 0) {
                return response()->json([
                    'message' => 'This user not active',
                    'status' => 'NotActive',
                ]);
            }

        } else {
            return response()->json([
                'message' => 'Incorrect Login',
                'status' => false,
            ]);
        }

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
