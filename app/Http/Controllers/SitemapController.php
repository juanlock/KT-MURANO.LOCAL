<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Product;
use App\Models\StoreSetting;
use Illuminate\Support\Facades\Schema;

class SitemapController extends Controller
{
    /**
     * XML sitemap for the storefront. URLs use the canonical base (custom domain
     * when configured), so search engines index the clean domain.
     */
    public function index()
    {
        $s = StoreSetting::first();

        $urls = [];
        $add = function (string $path, $lastmod = null, string $freq = 'weekly', string $priority = '0.6') use (&$urls, $s) {
            $urls[] = [
                'loc' => $s ? $s->storeUrl($path) : url('/'.ltrim($path, '/')),
                'lastmod' => $lastmod ? \Illuminate\Support\Carbon::parse($lastmod)->toAtomString() : null,
                'changefreq' => $freq,
                'priority' => $priority,
            ];
        };

        // Static storefront pages (base path follows Store Settings → Store URL)
        $add(store_path_to(), null, 'daily', '1.0');
        $add(store_path_to('shop'), null, 'daily', '0.9');
        $add(store_path_to('flash-sales'), null, 'daily', '0.5');
        $add(store_path_to('contact'), null, 'monthly', '0.3');

        // Products
        if (Schema::hasTable('products')) {
            Product::query()
                ->whereNull('deleted_at')
                ->where('is_active', 1)
                ->where('hide_from_online_store', 0)
                ->orderBy('id')
                ->get(['id', 'updated_at'])
                ->each(function ($p) use ($add) {
                    $add(store_path_to('product/'.$p->id), $p->updated_at, 'weekly', '0.8');
                });
        }

        // Published CMS pages
        if (Schema::hasTable('store_pages')) {
            \App\Models\StorePage::query()
                ->where('published', true)
                ->orderBy('id')
                ->get(['slug', 'updated_at'])
                ->each(function ($p) use ($add) {
                    if ($p->slug) {
                        $add(store_path_to('pages/'.$p->slug), $p->updated_at, 'monthly', '0.4');
                    }
                });
        }

        // Collections (browsed via the shop filter)
        if (Schema::hasTable('collections')) {
            Collection::query()
                ->orderBy('id')
                ->get(['id', 'slug', 'updated_at'])
                ->each(function ($c) use ($add) {
                    if ($c->slug) {
                        $add(store_path_to('shop').'?collection='.$c->slug, $c->updated_at, 'weekly', '0.5');
                    }
                });
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url>'."\n";
            $xml .= '    <loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>'."\n";
            if ($u['lastmod']) {
                $xml .= '    <lastmod>'.$u['lastmod'].'</lastmod>'."\n";
            }
            $xml .= '    <changefreq>'.$u['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$u['priority'].'</priority>'."\n";
            $xml .= '  </url>'."\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Dynamic robots.txt that references the sitemap.
     */
    public function robots()
    {
        $s = StoreSetting::first();
        $enabled = $s ? (bool) ($s->enabled ?? true) : true;

        $lines = ['User-agent: *'];
        if ($enabled) {
            $lines[] = 'Allow: /';
        } else {
            // Store turned off — discourage indexing.
            $lines[] = 'Disallow: /';
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.url('/sitemap.xml');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
