<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPortalLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;

/**
 * Client-portal language: the SPA fetches its messages from here and reports
 * the visitor's choice back so Blade (portal.blade.php, invoice PDFs) and the
 * API's own messages follow the same language.
 *
 * Both endpoints are public (guest login/set-password pages need them);
 * EnsurePortalAuth whitelists them.
 */
class PortalLocaleController extends Controller
{
    /**
     * GET /api/portal/translations/{locale} - the whole resources/lang/{locale}/portal.php
     * as a flat JSON map, exactly what vue-i18n consumes.
     */
    public function translations(string $locale)
    {
        if (! isset(SetLocale::SUPPORTED[$locale])) {
            return response()->json(['message' => __('portal.unsupported_locale')], 404);
        }

        $messages = Lang::get('portal', [], $locale);

        return response()->json(is_array($messages) ? $messages : [])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/portal/locale - current locale + supported set (used by the switcher).
     */
    public function show(Request $request)
    {
        return response()->json([
            'locale' => app()->getLocale(),
            'supported' => SetLocale::SUPPORTED,
        ]);
    }

    /**
     * POST /api/portal/locale - remember the visitor's choice.
     *
     * Session + cookie always (so guests keep it), and the signed-in client's
     * saved preference too so it follows them to other devices.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(SetLocale::SUPPORTED))],
        ]);

        $locale = $data['locale'];

        session([SetPortalLocale::SESSION_KEY => $locale]);
        Cookie::queue(SetPortalLocale::COOKIE_NAME, $locale, 60 * 24 * 365, '/');

        $portalClient = Auth::guard('portal')->user();
        if ($portalClient && $portalClient->preferred_locale !== $locale) {
            $portalClient->forceFill(['preferred_locale' => $locale])->save();
        }

        app()->setLocale($locale);

        return response()->json(['success' => true, 'locale' => $locale]);
    }
}
