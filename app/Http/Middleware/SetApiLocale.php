<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

/**
 * The API answers in the language the screen is showing (2026-11-13, "todo debe ser español para esa empresa"):
 * the SPA sends it as X-Locale (lib/http.js, from <html lang>), so __() in a controller's message, and the
 * validation messages, come back in Spanish to a Spanish screen. Without the header (portal, store, scripts)
 * nothing changes. SetPdfLocale runs after it: a PDF still follows the company's language.
 */
class SetApiLocale
{
    public function handle($request, Closure $next)
    {
        $locale = strtolower(substr((string) $request->header('X-Locale', ''), 0, 2));
        if ($locale !== '' && isset(SetLocale::SUPPORTED[$locale])) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
