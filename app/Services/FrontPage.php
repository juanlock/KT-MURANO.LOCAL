<?php

namespace App\Services;

use App\Models\StoreSetting;
use App\Support\CompanyDomain;
use Illuminate\Http\Request;

/**
 * The online store's front page, per company (Store Settings -> Front page):
 *  - 'sections': the store's own section-built front page (homepage_lineup)
 *  - 'imported': a page imported from a URL, served in its ORIGINAL format
 *    (directly -- no frame -- so it lives on the store's origin), with the
 *    Murano store bar inserted at import time (Shop / Cart with item count /
 *    My Account), linking to the company's online store.
 */
class FrontPage
{
    private const BAR_START = '<!-- murano:store-bar -->';
    private const BAR_END = '<!-- /murano:store-bar -->';

    /**
     * The company the storefront request is for: its own domain; else an
     * explicit ?company=ID (admin "view" links on the shared store URL --
     * pages/front pages are public anyway); else the shared store's.
     */
    public static function companyIdForRequest(Request $request): int
    {
        return self::explicitCompanyId($request)
            ?? (int) StoreSetting::query()->orderBy('id')->value('company_id');
    }

    /** Domain company, or a valid ?company=ID; null when neither applies. */
    public static function explicitCompanyId(Request $request): ?int
    {
        // On a company website's own address (/{url_path}/...): that company.
        if ($id = (int) $request->attributes->get('website_company')) {
            return $id;
        }
        if ($id = CompanyDomain::companyIdForHost($request->getHost())) {
            return $id;
        }
        $q = (int) $request->query('company', 0);

        return $q > 0 && \App\Models\Company::whereKey($q)->whereNull('deleted_at')->exists() ? $q : null;
    }

    /** The imported front page as a response, or null (-> section-built page). */
    public static function importedResponse(int $companyId)
    {
        $s = StoreSetting::where('company_id', $companyId)->first();
        if (! $s || $s->front_page_mode !== 'imported' || ! $s->front_page_path
            || ! str_starts_with($s->front_page_path, 'files/company_') || ! is_file(public_path($s->front_page_path))) {
            return null;
        }

        $html = file_get_contents(public_path($s->front_page_path));
        if (! str_contains($html, self::BAR_START)) {
            $html = self::withStoreBar($html);
        }
        // Served at "/" (or the store path), not from its folder: point the
        // page's relative links (CSS/JS/images/sub-pages) at the copy. Not a
        // <base> tag -- that would also redirect in-page "#anchor" links.
        $html = self::rebaseRelative($html, '/'.dirname($s->front_page_path).'/');
        // Store -> Portfolio section, live (after rebasing: its URLs are absolute).
        $html = Portfolio::injectInto($html, $companyId);
        $html = self::withBookingResize($html);

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Our booking frame (/book?...embed=1, Website pages -> "Use our booking
     * calendar") grows with the form: the form posts its height and this
     * listener -- ours, added here because company admins can't add scripts to
     * the page -- resizes the frame that sent it. Only when the page has one.
     */
    public static function withBookingResize(string $html): string
    {
        if (! preg_match('~<iframe\b[^>]*\ssrc=["\'][^"\']*/book\?~i', $html) || str_contains($html, 'murano-booking-resize')) {
            return $html;
        }
        $js = '<script id="murano-booking-resize">window.addEventListener("message",function(e){'
            .'if(!e.data||e.data.type!=="murano-booking-height")return;'
            .'document.querySelectorAll("iframe[src*=\'/book?\']").forEach(function(f){'
            .'if(f.contentWindow===e.source){f.style.height=(e.data.height+20)+"px";}});});</script>';

        return stripos($html, '</body>') !== false ? preg_replace('~</body>~i', $js.'</body>', $html, 1) : $html.$js;
    }

    /** Prefix relative href/src/srcset/url() references with $prefix. */
    private static function rebaseRelative(string $html, string $prefix): string
    {
        $isRelative = fn (string $u) => $u !== '' && ! preg_match('/^([a-z][a-z0-9+.-]*:|\/|#|\{)/i', $u);

        $html = preg_replace_callback('/\b(href|src|data-src|data-bg|poster|action)(\s*=\s*)(["\'])([^"\']*)\3/i',
            fn ($m) => $isRelative(trim($m[4])) ? $m[1].$m[2].$m[3].$prefix.ltrim(trim($m[4]), './').$m[3] : $m[0], $html);

        $html = preg_replace_callback('/\bsrcset(\s*=\s*)(["\'])([^"\']*)\2/i', function ($m) use ($isRelative, $prefix) {
            $parts = array_map(function ($p) use ($isRelative, $prefix) {
                $p = trim($p);
                [$u] = preg_split('/\s+/', $p, 2);

                return $isRelative($u) ? $prefix.ltrim($p, './') : $p;
            }, explode(',', $m[3]));

            return 'srcset'.$m[1].$m[2].implode(', ', $parts).$m[2];
        }, $html);

        return preg_replace_callback('/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
            fn ($m) => $isRelative(trim($m[2])) && ! str_starts_with(trim($m[2]), 'data:')
                ? 'url('.$m[1].$prefix.ltrim(trim($m[2]), './').$m[1].')' : $m[0], $html);
    }

    /** Store the imported front page for a company and insert the store bar. */
    public static function setImported(int $companyId, ?string $url, string $path): void
    {
        // A fresh import replaces the pages: their kept pre-edit copies are stale.
        SitePageEditor::forgetOriginals($companyId);

        $abs = public_path($path);
        if (is_file($abs)) {
            file_put_contents($abs, self::withStoreBar(file_get_contents($abs)));
        }
        StoreSetting::forCompany($companyId)->forceFill([
            'front_page_mode' => 'imported',
            'front_page_url' => $url,
            'front_page_path' => $path,
            'front_page_imported_at' => now(),
        ])->save();

        // Read the site's own navigation menu so Store -> Menus can edit it.
        try {
            FrontPageMenu::detect($companyId);
        } catch (\Throwable $e) {
            report($e); // the import itself still succeeded
        }
    }

    /** One-page template (2026-10-20): website_settings.onepage, built from the business type the first time. */
    public static function setOnePage(int $companyId): void
    {
        $w = \App\Models\WebsiteSetting::forCompany($companyId);
        if (! is_array($w->onepage) || empty($w->onepage['sections'])) {
            $w->onepage = \App\Support\OnePageTemplates::build($companyId);
            $w->save();
        }
        StoreSetting::forCompany($companyId)->forceFill(['front_page_mode' => 'onepage'])->save();
    }

    public static function setSections(int $companyId): void
    {
        StoreSetting::forCompany($companyId)->forceFill(['front_page_mode' => 'sections'])->save();
    }

    /** Insert (or refresh) the store bar right before </body>. Idempotent. */
    public static function withStoreBar(string $html): string
    {
        $html = preg_replace('/'.preg_quote(self::BAR_START, '/').'.*?'.preg_quote(self::BAR_END, '/').'/s', '', $html);
        $bar = self::storeBar();

        return stripos($html, '</body>') !== false
            ? substr_replace($html, $bar, strripos($html, '</body>'), 0)
            : $html.$bar;
    }

    private static function path(string $route): string
    {
        return parse_url(route($route), PHP_URL_PATH) ?: '/';
    }

    private static function storeBar(): string
    {
        $shop = e(self::path('store.shop'));
        $cart = e(self::path('checkout'));
        $account = e(self::path('account'));

        return self::BAR_START.<<<HTML

<style>
  #murano-store-bar{position:fixed;right:16px;bottom:16px;z-index:2147483000;display:flex;gap:4px;padding:6px;
    border-radius:999px;background:rgba(17,24,39,.92);box-shadow:0 6px 24px rgba(0,0,0,.25);font:600 13px/1 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
  #murano-store-bar a{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:999px;color:#fff !important;text-decoration:none !important}
  #murano-store-bar a:hover{background:rgba(255,255,255,.14)}
  #murano-store-bar .msb-count{min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#ef4444;color:#fff;font-size:11px;line-height:18px;text-align:center}
  #murano-store-bar .msb-count[hidden]{display:none}
  @media (max-width:480px){#murano-store-bar{left:12px;right:12px;bottom:12px;justify-content:space-around}}
</style>
<nav id="murano-store-bar" aria-label="Online store">
  <a href="{$shop}">&#128717; Shop</a>
  <a href="{$cart}">&#128722; Cart <span class="msb-count" hidden>0</span></a>
  <a href="{$account}">&#128100; My Account</a>
</nav>
<script>
(function(){
  // Same origin as the online store, so the store's cart is readable here.
  function count(){
    try{var c=JSON.parse(localStorage.getItem('shop.cart.v1')||'{}');
      return (c&&Array.isArray(c.items))?c.items.reduce(function(n,i){return n+(Number(i.qty)||0);},0):0;}catch(e){return 0;}
  }
  function paint(){var el=document.querySelector('#murano-store-bar .msb-count');if(!el)return;var n=count();el.textContent=n>99?'99+':String(n);el.hidden=!n;}
  paint();window.addEventListener('storage',paint);window.addEventListener('focus',paint);
})();
</script>
HTML.self::BAR_END;
    }
}
