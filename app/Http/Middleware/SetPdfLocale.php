<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleSyncController;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\App;

/**
 * The language of a PDF or printed document. It reaches a customer, so it speaks the company's language (2026-11-05: this
 * allowed only Arabic or English, so a Spanish company's invoices came out in English). A device set to Arabic keeps it, for
 * its right-to-left layout; with no company known (a guest, AdminSystem) the device's language decides.
 * Uses the cookie, so the API can stay stateless and auth (Passport) is not broken.
 */
class SetPdfLocale
{
    public function handle($request, Closure $next)
    {
        $path = $request->path();
        $isPdf = str_contains($path, '_pdf') || str_contains($path, '/pdf') || str_contains($path, '_print_html');

        if ($isPdf) {
            $cookie = $request->cookie(LocaleSyncController::COOKIE_NAME, config('app.locale'));
            $locale = $cookie === 'ar' ? 'ar' : ($this->companyLocale($request) ?: $cookie);
            App::setLocale(isset(SetLocale::SUPPORTED[$locale]) ? $locale : 'en');
        }

        return $next($request);
    }

    private function companyLocale($request): ?string
    {
        $user = $request->user('api');
        if (! $user) {
            return null;
        }
        $companyId = $user->type === 'SuperAdmin' ? (int) $request->input('company_id') : (int) $user->company_id;
        if (! $companyId || $companyId === Setting::TEMPLATE_COMPANY_ID) {
            return null;
        }

        return Setting::forCompany($companyId)->default_language ?: null;
    }
}
