<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Website Management -> Website URL (2026-10-06): every company website has its
 * OWN address, not the store's: http://host/{url_path} (e.g. /kcoltech), with
 * /{url_path}/pages/{slug}, /gallery and /contact. routes/web.php registers one
 * route group per path (map cached here); the WebsiteCompany middleware tells
 * the storefront controller whose website it is.
 */
class WebsitePaths
{
    private const CACHE = 'website_paths';

    /** First URL segments a website can never take (system, admin, store, other modules). */
    public static function reserved(): array
    {
        $storeFirst = explode('/', store_path())[0] ?? '';

        return array_values(array_filter(array_unique(array_merge(store_reserved_paths(), [
            'online_store', $storeFirst, 'site', 'lang', 'set-locale', 'pos', 'book', 'pay', 'support', 'portfolio', 'portfolio-theme',
            'files', 'public', 'admin', 'app', 'home', 'shop', 'cart', 'checkout', 'account', 'currency', 'gallery', 'contact',
            'pages', 'order-ready', 'salla', 'xero', 'google-sheets', 'storage', 'favicon.ico',
        ]))));
    }

    /** First URL segments of every registered route except the websites' own. */
    private static function routeSegments(): array
    {
        $sites = array_keys(self::all());
        $out = [];
        foreach (app('router')->getRoutes() as $r) {
            $first = explode('/', ltrim($r->uri(), '/'))[0];
            if ($first !== '' && ! str_contains($first, '{') && ! in_array($first, $sites, true)) {
                $out[$first] = true;
            }
        }

        return array_keys($out);
    }

    /** path => company_id, for the route groups. */
    public static function all(): array
    {
        try {
            return Cache::remember(self::CACHE, 600, function () {
                if (! Schema::hasTable('website_settings') || ! Schema::hasColumn('website_settings', 'url_path')) {
                    return [];
                }

                return DB::table('website_settings')->whereNotNull('url_path')->where('url_path', '!=', '')
                    ->pluck('company_id', 'url_path')->map(fn ($v) => (int) $v)->all();
            });
        } catch (\Throwable $e) {
            return []; // not installed yet / DB down: no website routes
        }
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE);
        } catch (\Throwable $e) {
        }
    }

    public static function pathOf(int $companyId): ?string
    {
        $p = array_search($companyId, self::all(), true);

        return $p === false ? null : (string) $p;
    }

    /** Is $path free for $companyId? (null = OK, else the reason) */
    public static function problem(string $path, int $companyId): ?string
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $path) || strlen($path) > 60) {
            return 'Use lowercase letters, numbers and dashes only (e.g. "my-company").';
        }
        if (in_array($path, self::reserved(), true) || in_array($path, self::routeSegments(), true)) {
            return 'That address is used by the system. Choose another one.';
        }
        if (DB::table('website_settings')->where('url_path', $path)->where('company_id', '!=', $companyId)->exists()) {
            return 'Another company already uses that address.';
        }

        return null;
    }

    /** A free path from the company name ("KcolTech" -> "kcoltech", "-2" when taken). */
    public static function suggest(int $companyId, ?string $name): string
    {
        $base = Str::slug((string) $name) ?: 'company-'.$companyId;
        $try = $base;
        for ($i = 2; self::problem($try, $companyId) !== null; $i++) {
            $try = $base.'-'.$i;
        }

        return $try;
    }

    /**
     * URL of a website page ('' home, 'pages/{slug}', 'gallery', 'contact'):
     * the website address of the company (the current request's website, else
     * $companyId's), else the old store URL with ?company=.
     */
    public static function url(string $rel = '', ?int $companyId = null): string
    {
        $req = request();
        $cid = $companyId ?: (int) $req->attributes->get('website_company');
        $path = $req->attributes->get('website_path') && (! $companyId || $companyId === (int) $req->attributes->get('website_company'))
            ? $req->attributes->get('website_path')
            : ($cid ? self::pathOf($cid) : null);
        $rel = trim($rel, '/');
        if ($path) {
            return url('/'.$path.($rel !== '' ? '/'.$rel : ''));
        }

        return store_base_url($rel).($cid ? '?company='.$cid : '');
    }
}
