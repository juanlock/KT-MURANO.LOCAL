<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\WebsiteDemoCatalog;
use App\Support\CompanyFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Website Management: example pages of the company website (store_pages),
 * written for what the company does -- IT consultant (from www.kcoltech.com),
 * landscaping, or unisex salon (barber shops too) -- with photos from the demo
 * library (resources/demo/portfolio/{set}) copied into the company's own
 * files/company_{id}/website/demo/. Published, so they open at
 * /online_store/pages/{slug}; the menus are not changed (add them in
 * Website Management -> Menus). purge() deletes the photo copies.
 *
 * Not blocked by the company's own pages (dataTables() is empty): demo pages
 * are added next to them, with a free slug; "already generated" stops a second run.
 */
class WebsiteModule extends DemoModule
{
    public function key(): string
    {
        return 'website';
    }

    public function label(): string
    {
        return 'Website pages (about, services, contact... for the business type)';
    }

    public function dataTables(): array
    {
        return [];
    }

    public function requiredTables(): array
    {
        return ['store_pages'];
    }

    public function generate(DemoContext $ctx): array
    {
        $cid = $ctx->companyId;
        $set = WebsiteDemoCatalog::setFor($ctx->businessType);
        $photoSet = $set; // the page sets use the same names as the portfolio photo sets
        $src = resource_path('demo/portfolio/'.$photoSet);
        $destRel = CompanyFiles::dir($cid, 'website').'/demo';
        if (! is_dir(public_path($destRel))) {
            mkdir(public_path($destRel), 0755, true);
        }
        $company = DB::table('companies')->where('id', $cid)->first();
        $vars = [
            '{{company}}' => e($company->name ?? 'Our company'),
            '{{phone}}' => e(($company->phone ?? '') ?: WebsiteDemoCatalog::FALLBACK['phone']),
            '{{email}}' => e(($company->email ?? '') ?: WebsiteDemoCatalog::FALLBACK['email']),
        ];

        $pages = $photos = 0;
        $copied = [];
        foreach (WebsiteDemoCatalog::PAGES[$set] as [$title, $slug, $seo, $html]) {
            $html = preg_replace_callback('/\{\{img:([a-z0-9-]+\.jpg)\}\}/', function ($m) use ($src, $destRel, &$copied, &$photos) {
                $file = $m[1];
                if (! isset($copied[$file])) {
                    if (! is_file("$src/$file")) {
                        return '';
                    }
                    $rel = $destRel.'/'.Str::lower(Str::random(6)).'-'.$file;
                    copy("$src/$file", public_path($rel));
                    $copied[$file] = $rel;
                    $photos++;
                }

                return '/'.$copied[$file];
            }, strtr($html, $vars));
            $html = preg_replace('#<img src=""[^>]*>#', '', $html);
            $ctx->insert('store_pages', [
                'title' => $title,
                'slug' => $this->freeSlug($cid, $slug),
                'content' => trim($html),
                'seo_title' => $title.' — '.($company->name ?? ''),
                'seo_description' => $seo,
                'published' => 1,
            ]);
            $pages++;
        }

        return [
            'Business type' => \App\Support\BusinessTypes::LABELS[$ctx->businessType] ?? $ctx->businessType,
            'Page set' => $set === 'it' ? 'IT consultant (kcoltech.com)' : $set,
            'Pages' => $pages,
            'Photos' => $photos,
        ];
    }

    /** Delete the photo copies used by this company's demo pages before the rows go. */
    public function purge(DemoContext $ctx): void
    {
        $prefix = CompanyFiles::dir($ctx->companyId, 'website').'/demo/';
        $html = DB::table('store_pages')->whereIn('id', $ctx->demoIds('store_pages'))->pluck('content')->implode(' ');
        preg_match_all('#/('.preg_quote($prefix, '#').'[A-Za-z0-9._-]+)#', $html, $m);
        foreach (array_unique($m[1]) as $p) {
            if (! str_contains($p, '..') && is_file(public_path($p))) {
                @unlink(public_path($p));
            }
        }
    }

    private function freeSlug(int $cid, string $slug): string
    {
        $try = $slug;
        for ($i = 2; DB::table('store_pages')->where('company_id', $cid)->where('slug', $try)->exists(); $i++) {
            $try = $slug.'-'.$i;
        }

        return $try;
    }
}
