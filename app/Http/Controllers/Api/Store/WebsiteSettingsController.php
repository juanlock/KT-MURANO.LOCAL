<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\StorePage;
use App\Models\StoreSetting;
use App\Models\WebsiteSetting;
use App\Services\WebsiteSnapshots;
use App\Support\CompanyFiles;
use App\Support\WebsitePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Website Management's OWN settings (2026-10-06), separate from the Online
 * Store's: name, logo, favicon, SEO, top bar & footer, social links, menus
 * (App\Models\WebsiteSetting, one row per company). The acting company's row;
 * same permission as the store settings screens. Files go to
 * files/company_{id}/website/.
 *
 *  GET  admin/website/settings   -> {settings, locales}
 *  POST admin/website/settings   (multipart) site_name, seo_*, topbar_*, footer_text,
 *                                social_links (JSON), text_translations (JSON), logo, favicon,
 *                                remove_logo / remove_favicon
 *  GET  admin/website/menus      -> {menus, pages, collections: [], locales}
 *  POST admin/website/menus      {menus}
 */
class WebsiteSettingsController extends Controller
{
    use ScopesToCompany;

    private function companyId(Request $request): int
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        return (int) $this->resolveActingCompanyId($request);
    }

    public function show(Request $request)
    {
        $cid = $this->companyId($request);

        return response()->json(['settings' => WebsiteSetting::forCompany($cid), 'locales' => store_locales()] + $this->urlInfo($cid));
    }

    /** Website URL card: the public address and the origin it hangs off. */
    private function urlInfo(int $cid): array
    {
        return ['public_url' => WebsitePaths::url('', $cid), 'url_origin' => rtrim(url('/'), '/').'/'];
    }

    public function update(Request $request)
    {
        $cid = $this->companyId($request);
        $data = $request->validate([
            'url_path' => 'nullable|string|max:60',
            'site_name' => 'nullable|string|max:190',
            'seo_meta_title' => 'nullable|string|max:255',
            'seo_meta_description' => 'nullable|string|max:1000',
            'seo_title_template' => 'nullable|string|max:190',
            'topbar_text_left' => 'nullable|string|max:190',
            'topbar_text_right' => 'nullable|string|max:190',
            'footer_text' => 'nullable|string|max:255',
            'social_links' => 'nullable',
            'text_translations' => 'nullable',
            'logo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
            'favicon' => 'nullable|file|mimes:jpg,jpeg,png,webp,ico|max:2048',
            'remove_logo' => 'nullable|boolean',
            'remove_favicon' => 'nullable|boolean',
        ]);
        $w = WebsiteSetting::forCompany($cid);
        $dir = CompanyFiles::dir($cid, 'website');

        foreach (['social_links', 'text_translations'] as $k) {
            if (array_key_exists($k, $data) && is_string($data[$k])) {
                $data[$k] = json_decode($data[$k], true) ?: null;
            }
        }
        if (array_key_exists('text_translations', $data) && is_array($data['text_translations'])) {
            // Only this website's translatable fields, only known locales, no blanks.
            $clean = [];
            foreach (WebsiteSetting::TRANSLATABLE_TEXT as $f) {
                foreach ((array) ($data['text_translations'][$f] ?? []) as $loc => $txt) {
                    if (isset(store_locales()[$loc]) && trim((string) $txt) !== '') {
                        $clean[$f][$loc] = mb_substr(trim((string) $txt), 0, 1000);
                    }
                }
            }
            $data['text_translations'] = $clean ?: null;
        }

        foreach (['logo' => [200, 'logo_path'], 'favicon' => [64, 'favicon_path']] as $field => [$size, $col]) {
            if ($request->boolean('remove_'.$field) || $request->hasFile($field)) {
                $this->dropFile($w->{$col}, $cid);
                $data[$col] = null;
            }
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'png');
                $name = (string) Str::uuid().'.'.($ext === 'ico' ? 'ico' : ($field === 'favicon' ? 'png' : $ext));
                if ($ext === 'ico') {
                    $file->move(public_path($dir), $name);
                } else {
                    $img = Image::make($file->getRealPath());
                    $field === 'favicon'
                        ? $img->fit($size, $size)->encode('png')->save(public_path($dir.'/'.$name))
                        : $img->resize($size, $size, function ($c) {
                            $c->aspectRatio();
                            $c->upsize();
                        })->encode($ext, 85)->save(public_path($dir.'/'.$name));
                }
                $data[$col] = $dir.'/'.$name;
            }
            unset($data[$field], $data['remove_'.$field]);
        }

        if (array_key_exists('url_path', $data)) {
            $path = strtolower(trim((string) $data['url_path'], " /\t"));
            if ($path === '') {
                $path = WebsitePaths::suggest($cid, DB::table('companies')->where('id', $cid)->value('name'));
            } elseif ($why = WebsitePaths::problem($path, $cid)) {
                throw ValidationException::withMessages(['url_path' => $why]);
            }
            $data['url_path'] = $path;
        }

        $w->fill($data)->save();
        WebsitePaths::forget();

        return response()->json(['success' => true, 'settings' => $w->fresh()] + $this->urlInfo($cid));
    }

    /** Saved websites: copies of the imported website kept before each import. */
    public function snapshots(Request $request)
    {
        return response()->json(['snapshots' => WebsiteSnapshots::list($this->companyId($request))]);
    }

    public function useSnapshot(Request $request, int $id)
    {
        $cid = $this->companyId($request);
        WebsiteSnapshots::use($cid, $id);

        return response()->json(['snapshots' => WebsiteSnapshots::list($cid),
            'settings' => StoreSetting::forCompany($cid)->only(['front_page_mode', 'front_page_url', 'front_page_path', 'front_page_imported_at'])]);
    }

    public function deleteSnapshot(Request $request, int $id)
    {
        $cid = $this->companyId($request);
        WebsiteSnapshots::delete($cid, $id);

        return response()->json(['snapshots' => WebsiteSnapshots::list($cid)]);
    }

    public function menus(Request $request)
    {
        $cid = $this->companyId($request);
        $w = WebsiteSetting::forCompany($cid);

        return response()->json([
            'menus' => SettingsApiController::normalizeMenus(is_array($w->menus) ? $w->menus : []),
            'pages' => StorePage::where('company_id', $cid)->orderBy('title')->get(['id', 'title', 'slug', 'published']),
            'collections' => [],
            'locales' => store_locales(),
        ]);
    }

    public function updateMenus(Request $request)
    {
        $cid = $this->companyId($request);
        $menus = SettingsApiController::normalizeMenus($request->validate(['menus' => 'nullable'])['menus'] ?? []);
        $w = WebsiteSetting::forCompany($cid);
        $w->menus = $menus;
        $w->save();

        return response()->json(['menus' => $menus]);
    }

    /** Delete a file only when it is this company's own website file. */
    private function dropFile(?string $path, int $cid): void
    {
        $p = (string) $path;
        if ($p !== '' && str_starts_with($p, 'files/company_'.$cid.'/website/') && ! str_contains($p, '..') && File::exists(public_path($p))) {
            File::delete(public_path($p));
        }
    }
}
