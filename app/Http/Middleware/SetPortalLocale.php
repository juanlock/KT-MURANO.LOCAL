<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Client-portal locale resolution. Runs after SetLocale (web group) and
 * overrides it for portal requests only, so the portal keeps its own language
 * even though it shares the session cookie with the admin app and storefront.
 *
 * Precedence, highest first:
 *
 *   1. session(SESSION_KEY)   — the header switcher, an explicit choice this session
 *   2. the signed-in portal client's saved preference (follows them across devices)
 *   3. cookie(COOKIE_NAME)    — a choice made on this device before signing in
 *   4. config('app.locale')
 *
 * The supported set is SetLocale::SUPPORTED — one resources/lang/<locale>/portal.php each.
 */
class SetPortalLocale
{
    public const SESSION_KEY = 'portal_locale';

    public const COOKIE_NAME = 'portal_locale';

    public function handle($request, Closure $next)
    {
        $supported = SetLocale::SUPPORTED;

        $candidates = [
            Session::get(self::SESSION_KEY),
            $this->savedPreference(),
            $request->cookie(self::COOKIE_NAME),
            config('app.locale'),
        ];

        foreach ($candidates as $locale) {
            if ($locale && isset($supported[$locale])) {
                App::setLocale($locale);
                break;
            }
        }

        return $next($request);
    }

    private function savedPreference(): ?string
    {
        try {
            return optional(Auth::guard('portal')->user())->preferred_locale ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
