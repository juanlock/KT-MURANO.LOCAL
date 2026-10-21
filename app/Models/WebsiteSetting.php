<?php

namespace App\Models;

use App\Support\CompanyFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Website Management's own settings, one row per company (2026-10-06): name,
 * logo, favicon, SEO, top bar & footer, social links, menus -- separate from the
 * Online Store's (StoreSetting). The website pages (our theme's front page,
 * pages, gallery, contact) are rendered with applyTo(): the store settings with
 * these fields laid over them. Files: files/company_{id}/website/.
 */
class WebsiteSetting extends Model
{
    /** Columns shared in name with store_settings (copied at creation, laid over by applyTo()). */
    public const FIELDS = [
        'logo_path', 'favicon_path', 'seo_meta_title', 'seo_meta_description', 'seo_title_template',
        'topbar_text_left', 'topbar_text_right', 'footer_text', 'social_links', 'menus',
    ];

    /** Text fields translated per language (text_translations), like the store's. */
    public const TRANSLATABLE_TEXT = ['topbar_text_left', 'topbar_text_right', 'footer_text', 'seo_meta_title', 'seo_meta_description'];

    protected $fillable = [
        'company_id', 'url_path', 'site_name', 'logo_path', 'favicon_path', 'seo_meta_title', 'seo_meta_description', 'seo_title_template',
        'topbar_text_left', 'topbar_text_right', 'footer_text', 'social_links', 'menus', 'text_translations', 'onepage',
    ];

    // onepage: the One-page template website (App\Support\OnePageTemplates), 2026-10-20.
    protected $casts = ['social_links' => 'array', 'menus' => 'array', 'text_translations' => 'array', 'onepage' => 'array'];

    /** The company's row; the first time, a copy of its store settings (files copied too). */
    public static function forCompany(int $companyId): self
    {
        if ($row = static::where('company_id', $companyId)->first()) {
            return $row;
        }
        $store = StoreSetting::where('company_id', $companyId)->first();
        $data = ['company_id' => $companyId, 'site_name' => $store->store_name ?? null];
        foreach (self::FIELDS as $f) {
            $data[$f] = $store->{$f} ?? null;
        }
        $data['logo_path'] = self::copyFile($companyId, $store->logo_path ?? null);
        $data['favicon_path'] = self::copyFile($companyId, $store->favicon_path ?? null);
        // The top bar is the shop's promotions ("Free shipping…"): the website starts without one.
        $data['topbar_text_left'] = $data['topbar_text_right'] = null;
        $tr = (array) ($store->text_translations ?? []);
        $data['text_translations'] = array_intersect_key($tr, array_flip(['footer_text', 'seo_meta_title', 'seo_meta_description'])) ?: null;

        // The website is the COMPANY's: the store's generic product defaults
        // ("StoreX", "Online Store", no logo) are replaced by the company's own.
        $company = \Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->first(['name', 'logo']);
        $generic = fn ($v) => trim((string) $v) === '' || in_array(mb_strtolower(trim((string) $v)), ['storex', 'online store', 'store'], true);
        if ($company && $company->name) {
            if ($generic($data['site_name'])) {
                $data['site_name'] = $company->name;
            }
            if ($generic($data['seo_meta_title'])) {
                $data['seo_meta_title'] = $company->name;
                unset($data['text_translations']['seo_meta_title']);
            }
        }
        // No store logo: the company logo (companies.logo, else the company's system settings logo).
        $logo = ($company->logo ?? null) ?: \Illuminate\Support\Facades\DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('logo');
        if (! $data['logo_path'] && $logo) {
            $logo = str_starts_with($logo, 'files/') || str_starts_with($logo, 'images/') ? $logo : 'images/'.$logo;
            $data['logo_path'] = self::copyFile($companyId, is_file(public_path($logo)) ? $logo : null);
        }

        // Its own address from the company name (/kcoltech) -- Website URL.
        if ($companyId !== 999 && \Illuminate\Support\Facades\Schema::hasColumn('website_settings', 'url_path')) {
            $data['url_path'] = \App\Support\WebsitePaths::suggest($companyId, $company->name ?? null);
        }
        $row = static::create($data);
        \App\Support\WebsitePaths::forget();

        return $row;
    }

    /** A copy of a store file in the company's website folder (the path of the copy), or the same path when it can't be copied. */
    private static function copyFile(int $companyId, ?string $path): ?string
    {
        if (! $path || str_starts_with($path, 'http') || ! is_file(public_path($path))) {
            return $path;
        }
        $dir = CompanyFiles::dir($companyId, 'website');
        $dest = $dir.'/'.Str::lower(Str::random(6)).'-'.basename($path);

        return @copy(public_path($path), public_path($dest)) ? $dest : $path;
    }

    /**
     * The store settings as the WEBSITE pages must see them: a copy of $store
     * with this website's name, logo, favicon, SEO, top bar / footer, social
     * links, menus (and their translations) laid over it.
     */
    public function applyTo(StoreSetting $store): StoreSetting
    {
        $s = clone $store;
        $over = [];
        foreach (self::FIELDS as $f) {
            $over[$f] = $this->{$f};
        }
        if ($this->site_name) {
            $over['store_name'] = $this->site_name;
        }
        $tr = (array) ($store->text_translations ?? []);
        foreach (self::TRANSLATABLE_TEXT as $f) {
            unset($tr[$f]);
        }
        $over['text_translations'] = $tr + (array) ($this->text_translations ?? []);
        $s->forceFill($over);

        return $s;
    }
}
