<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * A company website's own address (/{url_path}/...): tells the storefront
 * controller whose website this is (FrontPage::explicitCompanyId reads it) and
 * the base path for its links (App\Support\WebsitePaths::url). Route groups in
 * routes/web.php: ->middleware('website.company:{company_id},{path}').
 */
class WebsiteCompany
{
    public function handle(Request $request, Closure $next, string $companyId, string $path)
    {
        $request->attributes->set('website_company', (int) $companyId);
        $request->attributes->set('website_path', $path);
        // The website's product blocks show only its company's catalogue (ScopedToStoreCompany, 2026-11-25).
        app()->instance('store.company_id', (int) $companyId);
        // The visit stays in this company's store: its links to /online_store/shop?q=... carry no company (2026-11-25,
        // /hordex -> "Ver basureros" opened the default English store).
        // A cookie, not the session: the store pages use their own session cookie (is_store_request).
        \Illuminate\Support\Facades\Cookie::queue('store_company', (string) (int) $companyId, 60 * 24 * 30, '/');

        return $next($request);
    }
}
