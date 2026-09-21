<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;

/**
 * Syncs the Vue i18n selected language to a cookie, so the server-rendered
 * pages (Blade PDFs, and every 'web' page through SetLocale) follow the
 * language picked in the app — including the logged-out /login page, which is
 * why the cookie outlives the session.
 *
 * Cookie, not session: adding session to the API group would break
 * Passport/auth. The cookie is listed in EncryptCookies::$except so 'web'
 * routes can read what this 'api' route writes.
 */
class LocaleSyncController extends Controller
{
    public const COOKIE_NAME = 'app_locale';

    public function sync(Request $request)
    {
        $locale = $request->input('locale', $request->header('X-Locale'));

        if (! is_string($locale) || $locale === '') {
            $appLocale = $request->cookie(self::COOKIE_NAME, 'en');
            return response()->json(['ok' => true, 'locale' => $appLocale]);
        }

        // Any locale the app actually ships (en/fr/es/ar), not just ar vs en
        // — SetLocale validates against this same list.
        $locale = strtolower(substr($locale, 0, 5));
        $appLocale = isset(SetLocale::SUPPORTED[$locale]) ? $locale : 'en';

        $cookie = cookie(
            self::COOKIE_NAME,
            $appLocale,
            60 * 24 * 365, // 1 year in minutes
            '/',
            null,
            false, // secure
            false, // httpOnly - allow JS read if needed
            false,
            'lax'
        );

        return response()->json(['ok' => true, 'locale' => $appLocale])->cookie($cookie);
    }
}
