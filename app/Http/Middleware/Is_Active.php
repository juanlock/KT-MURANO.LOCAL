<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class Is_Active
{
    /**
     * A disabled login (users.statut = 0 -- Users -> Disabled, a removed
     * employee) is refused BEFORE the request runs (2026-10-07; it used to be
     * checked after, so the request still went through), on the route's own
     * guard (api or web).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->statut) {
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Your account is disabled. Ask an administrator.'], 401);
            }

            return redirect('/login')->with('erro_login', 'Your account is disabled.');
        }

        return $next($request);
    }
}
