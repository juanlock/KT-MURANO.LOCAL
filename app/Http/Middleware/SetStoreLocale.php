<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Storefront locale. Precedence, highest first:
 *
 *   1. the visitor's pick in THIS store's header switcher (session, then cookie, keyed by the store's company)
 *   2. the signed-in customer's saved preference (follows them across devices)
 *   3. store_settings()->language -- the store's own language
 *   4. config('app.locale')
 *
 * 2026-11-25 (user: Hordex's store showed English, "este cliente está en spanish"): the plain session('locale') /
 * cookie('locale') are shared by the admin panel and every company's store on the same domain, so an English admin
 * panel or another store turned a Spanish store into English. They no longer decide a store's language.
 */
class SetStoreLocale
{
    public static function key(): string
    {
        $cid = \App\Models\StoreSetting::currentCompanyId() ?: (int) (store_settings()->company_id ?? 0);

        return 'store_locale_'.$cid;
    }

    public function handle($request, Closure $next)
    {
        $supported = SetLocale::SUPPORTED;
        $key = self::key();

        $candidates = [
            Session::get($key),
            $request->cookie($key),
            optional(Auth::guard('store')->user())->preferred_locale,
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

    /** The store's own language, if the settings row is readable. */
    private function storeDefault(): ?string
    {
        try {
            return store_settings()->language ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
