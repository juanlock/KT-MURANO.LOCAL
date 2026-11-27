<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * A dashboard board's data only for the roles that have that board (the user, 2026-11-27: "limita el acceso a los tableros
 * por roles -- director y admin ven todo, administración y operaciones solo sus tableros"). The role's list is
 * roles.dashboard_tabs (Roles -> "Pestañas del tablero"); a role without a list (null / empty) keeps every board its
 * permissions allow, and the SuperAdmin always passes. Usage: ->middleware('dashtab:transport_profit,transport_risks')
 * -- the request passes when the role has ANY of the boards named.
 */
class DashboardTab
{
    public function handle(Request $request, Closure $next, string ...$tabs)
    {
        $user = $request->user('api');
        if ($user && $user->type !== 'SuperAdmin') {
            $list = json_decode((string) ($user->roles()->first()?->dashboard_tabs ?? ''), true);
            if (is_array($list) && $list && ! array_intersect($tabs, $list)) {
                abort(403, __('Your role does not have this board.'));
            }
        }

        return $next($request);
    }
}
