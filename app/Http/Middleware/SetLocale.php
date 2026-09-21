<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleSyncController;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\App;
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
        // 3. Settings -> default_language — the company default, for a device
        //    that has made no choice of its own.
        // 4. config('app.locale')
        $locale = Session::get('locale')
            ?: $request->cookie(LocaleSyncController::COOKIE_NAME)
            ?: $request->cookie('locale')
            ?: self::settingsLocale()
            ?: config('app.locale');

        if (! isset(self::SUPPORTED[$locale])) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }

    /**
     * Settings → default_language, cached: this runs on every 'web' request and
     * must not add a query to each one. Null while there is no DB/settings row
     * yet (installer and update flows both hit web routes).
     */
    public static function settingsLocale(): ?string
    {
        return Cache::remember('settings.default_language', 600, function () {
            try {
                if (! Schema::hasTable('settings')) {
                    return null;
                }

                return Setting::query()->value('default_language') ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }
}
