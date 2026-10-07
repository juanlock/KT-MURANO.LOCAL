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

        return $next($request);
    }
}
