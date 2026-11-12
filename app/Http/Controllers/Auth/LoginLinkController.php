<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\LoginLinks;
use App\Support\LoginSecurity;
use App\Support\WebLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /acceso/{code} (2026-11-12): a sign-in link sent by WhatsApp (App\Support\LoginLinks). The first device that
 * opens it is the only one it works on; then the person lands on their role's home page without a password.
 */
class LoginLinkController extends Controller
{
    public function enter(Request $request, string $code)
    {
        [$user, $reason] = LoginLinks::check($code, $request);
        if ($reason === null) {
            LoginSecurity::event($user, $user->email, 'web', 'link_login', $request);

            return WebLogin::complete($request, $user, true);
        }
        if ($user) {
            LoginSecurity::event($user, $user->email, 'web', 'link_refused', $request, $reason);
        }
        $lang = $user ? (string) DB::table('settings')->where('company_id', $user->company_id)->whereNull('deleted_at')->value('default_language') : '';
        $es = $lang === '' || str_starts_with($lang, 'es');

        return response()->view('auth.login_link_refused', ['reason' => $reason, 'es' => $es], $reason === 'unknown' ? 404 : 403);
    }
}
