<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Storefront locale resolution, layered on top of SetLocale (which only knows
 * about the session/cookie). Precedence, highest first:
 *
 *   1. session('locale')  — the header switcher, an override for this session
 *   2. the signed-in customer's saved preference (follows them across devices)
 *   3. cookie('locale')   — a guest's choice from an earlier visit
 *   4. store_settings()->language — the admin's storefront default
 *   5. config('app.locale')
 */
class SetStoreLocale
{
    public function handle($request, Closure $next)
    {
        $supported = SetLocale::SUPPORTED;

        $candidates = [
            Session::get('locale'),
            optional(Auth::guard('store')->user())->preferred_locale,
            $request->cookie('locale'),
            $this->storeDefault(),
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

    /** The admin-configured storefront default, if the settings row is readable. */
    private function storeDefault(): ?string
    {
        try {
            return store_settings()->language ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
