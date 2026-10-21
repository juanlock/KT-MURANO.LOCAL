<?php

namespace App\Services;

use App\Http\Middleware\SetLocale;
use App\Models\StoreSetting;
use App\Models\WebsiteSetting;
use App\Support\ModuleFlags;
use App\Support\OnePageTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The One-page template website (2026-10-20) as the company's front page, when
 * Website settings -> Front page = "One-page template" (store_settings.front_page_mode
 * = 'onepage') and Website Management is on. Data: website_settings.onepage
 * (App\Support\OnePageTemplates); contact details, gallery and portfolio live
 * from the company. View: resources/views/website/onepage.blade.php.
 */
class OnePage
{
    public static function response(int $companyId, Request $request, bool $preview = false)
    {
        if (! $companyId || ! ModuleFlags::enabled($companyId, 'website')) {
            return null;
        }
        if (! $preview && StoreSetting::where('company_id', $companyId)->value('front_page_mode') !== 'onepage') {
            return null;
        }
        $w = WebsiteSetting::forCompany($companyId);
        $page = is_array($w->onepage) && ! empty($w->onepage['sections']) ? $w->onepage : OnePageTemplates::build($companyId);
        $loc = self::locale($request, $companyId);
        $t = function ($v) use ($loc) {
            if (! is_array($v)) {
                return (string) $v;
            }

            return (string) (($v[$loc] ?? '') !== '' ? $v[$loc] : (($v['en'] ?? '') !== '' ? $v['en'] : ($v['es'] ?? '')));
        };

        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first();
        $company = DB::table('companies')->where('id', $companyId)->first(['id', 'name', 'email', 'phone', 'logo']);
        // The store's factory footer ("A beautiful demo storefront…") is not the company's: use © year + name.
        $footer = $t((array) (($w->text_translations ?? [])['footer_text'] ?? []) + ['en' => $w->footer_text]);
        if (stripos($footer, 'demo storefront') !== false) {
            $footer = '';
        }
        $sections = array_values(array_filter($page['sections'], fn ($s) => ! empty($s['on'])));
        $types = array_column($sections, 'type');

        $data = [
            'page' => $page,
            'sections' => $sections,
            'loc' => $loc,
            't' => $t,
            'site' => $w,
            'siteName' => $w->site_name ?: ($company->name ?? ''),
            'logo' => $w->logo_path,
            'favicon' => $w->favicon_path,
            'contact' => [
                'phone' => $settings->CompanyPhone ?? ($company->phone ?? null),
                'email' => $settings->email ?? ($company->email ?? null),
                'address' => $settings->CompanyAdress ?? null,
            ],
            'gallery' => in_array('gallery', $types, true) && Schema::hasTable('store_gallery_images')
                ? DB::table('store_gallery_images')->where('company_id', $companyId)->where('active', 1)->orderBy('sort_order')->orderBy('id')->limit(12)->get(['image', 'title'])
                : collect(),
            'portfolio' => in_array('portfolio', $types, true) && Schema::hasTable('portfolio_projects')
                ? DB::table('portfolio_projects')->where('company_id', $companyId)->where('published', 1)->orderByDesc('featured')->orderBy('sort_order')->limit(6)->get(['title', 'cover', 'description'])
                : collect(),
            'social' => array_values(array_filter((array) ($w->social_links ?? []), fn ($l) => is_array($l) && ! empty($l['url']))),
            'footer' => $footer ?: '© '.date('Y').' '.($w->site_name ?: ($company->name ?? '')),
            'seoTitle' => $t((array) (($w->text_translations ?? [])['seo_meta_title'] ?? []) + ['en' => $w->seo_meta_title]) ?: ($w->site_name ?: ($company->name ?? '')),
            'seoDescription' => $t((array) (($w->text_translations ?? [])['seo_meta_description'] ?? []) + ['en' => $w->seo_meta_description]),
            'locales' => array_values(array_intersect(OnePageTemplates::LOCALES, array_keys(SetLocale::SUPPORTED))),
            'preview' => $preview,
        ];

        return response()->view('website.onepage', $data);
    }

    /** ?lang= (remembered for the session), else the visitor's earlier pick, else the company's default language, else the browser's; en / es. */
    private static function locale(Request $request, int $companyId): string
    {
        $ok = fn ($l) => in_array($l, OnePageTemplates::LOCALES, true) ? $l : null;
        if ($l = $ok($request->query('lang'))) {
            session(['locale' => $l]);
            app()->setLocale($l);

            return $l;
        }
        $l = $ok(session('locale')) ?? $ok($request->cookie('locale'))
            ?? $ok(DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('default_language'))
            ?? $ok(SetLocale::browserLocale($request)) ?? 'en';
        app()->setLocale($l);

        return $l;
    }
}
