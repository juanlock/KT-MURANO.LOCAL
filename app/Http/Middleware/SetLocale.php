<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleSyncController;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

class SetLocale
{
    /** Supported locales => display name (one resources/lang bundle each). */
    public const SUPPORTED = [
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'ar' => 'العربية',
    ];

    public function handle($request, Closure $next)
    {
        // 1. session   — set by the storefront account pages
        // 2. app_locale — the admin app's own language choice, synced to a
        //    1-year cookie by /api/sync-locale so it outlives the session and
        //    is still there on the logged-out /login page. This used to read a
        //    cookie named 'locale', which nothing writes, so the choice never
        //    reached the server.
        // 3. the browser's language (user's rule, 2026-10-19): a device that
        //    has made no choice of its own opens Murano -- from the login page
        //    on -- in its computer's language (a PC in Peru set to Spanish ->
        //    Spanish), when it's one of the active languages.
        // 4. Settings -> default_language — the company default, when the
        //    browser's language is not one we have.
        // 5. config('app.locale')
        $locale = Session::get('locale')
            ?: $request->cookie(LocaleSyncController::COOKIE_NAME)
            ?: $request->cookie('locale')
            ?: self::browserLocale($request)
            ?: self::settingsLocale()
            ?: config('app.locale');

        if (! isset(self::SUPPORTED[$locale])) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }

    /**
     * The browser's preferred language (Accept-Language), only when it is one
     * of the ACTIVE languages (Settings -> Languages, `languages.is_active`)
     * the app ships -- today English and Spanish. Null otherwise.
     */
    public static function browserLocale($request): ?string
    {
        if (! $request->headers->has('Accept-Language')) {
            return null;
        }
        $active = Cache::remember('languages.active_supported', 600, function () {
            try {
                $codes = Schema::hasTable('languages')
                    ? \Illuminate\Support\Facades\DB::table('languages')->where('is_active', 1)->pluck('locale')->all()
                    : ['en'];
            } catch (\Throwable $e) {
                $codes = ['en'];
            }

            return array_values(array_intersect(array_keys(self::SUPPORTED), $codes));
        });
        if (! $active) {
            return null;
        }
        // Accept-Language order, by the 2-letter language (es-PE -> es).
        foreach ($request->getLanguages() as $lang) {
            $code = strtolower(substr($lang, 0, 2));
            if (in_array($code, $active, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Settings → default_language, cached: this runs on every 'web' request and
     * must not add a query to each one. Null while there is no DB/settings row
     * yet (installer and update flows both hit web routes).
     *
     * No acting-company header exists on guest/Blade routes, so the acting
     * company is the logged-in user's own company_id, or null for a guest
     * (Setting::forCompany(null) resolves to the 999 System template row).
     */
    public static function settingsLocale(): ?string
    {
        $companyId = Auth::check() ? (int) Auth::user()->company_id : null;

        return Cache::remember('settings.default_language.'.($companyId ?? 'guest'), 600, function () use ($companyId) {
            try {
                if (! Schema::hasTable('settings')) {
                    return null;
                }

                return Setting::forCompany($companyId)->default_language ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }
}
