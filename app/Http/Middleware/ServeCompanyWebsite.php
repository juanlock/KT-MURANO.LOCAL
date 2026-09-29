<?php

namespace App\Http\Middleware;

use App\Models\StorePage;
use App\Support\CompanyDomain;
use Closure;
use Illuminate\Http\Request;

/**
 * A request on a company's own domain (companies.domains) at the site root
 * ("/" or "/index.html") gets that company's website:
 *  - an IMPORTED website (Home page with the original design on) is shown
 *    full-screen;
 *  - otherwise the company uses the DEFAULT online store -> redirect to the
 *    store's home (its pages are served by /pages/{slug} for that domain).
 * Every other path (admin app, API, the store itself, the imported site's
 * own files) passes through.
 */
class ServeCompanyWebsite
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('GET') || ! in_array($request->path(), ['/', 'index.html', 'index.php'], true)) {
            return $next($request);
        }

        $companyId = CompanyDomain::companyIdForHost($request->getHost());
        if (! $companyId) {
            return $next($request);
        }

        // Store Settings -> Front page: imported page in its original format
        // (with the store bar), served directly at the domain root.
        if ($imported = \App\Services\FrontPage::importedResponse($companyId)) {
            return $imported;
        }

        // Default online store (store at the domain root already? then let
        // the store's own "/" route handle it).
        if (store_path() === '') {
            return $next($request);
        }

        return redirect()->route('store.index');
    }
}
