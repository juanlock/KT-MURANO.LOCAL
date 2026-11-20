<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\StoreSetting;
use Illuminate\Http\Request;

/**
 * Serves the PWA manifests for every installable surface (admin/POS, online
 * store, customer display, client portal).
 *
 * These replace the hardcoded manifest route and the static
 * public/manifest-*.webmanifest files: the admin manifest is built entirely
 * from System Settings → PWA (name, colors, display mode, launch URL) and the
 * other surfaces inherit the same brand name with their own suffix, so
 * renaming the app renames every installed shortcut.
 *
 * Nothing here is required for a valid manifest — every field falls back to the
 * value the app used before these settings existed, so an unconfigured (or
 * un-migrated) install behaves exactly as it did.
 */
class PwaManifestController extends Controller
{
    /**
     * Per-surface defaults, mirrored from the original static manifests so each
     * installed surface keeps its own scope, theme and display behavior. Only
     * the 'app' surface is user-configurable; the others are fixed because
     * their look is dictated by the screen they run on (a customer display is
     * deliberately dark and fullscreen, for example).
     */
    protected array $surfaces = [
        'app' => [
            'suffix' => null,
            'short_name' => 'KcolTech - Murano',
            'description' => 'Ultimate Inventory with POS',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => '#2f3640',
        ],
        'store' => [
            'suffix' => 'Store',
            'short_name' => 'Store',
            'description' => 'Online Store',
            'start_url' => '/online_store',
            'scope' => '/online_store',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => '#6c5ce7',
        ],
        'customer-display' => [
            'suffix' => 'Display',
            'short_name' => 'Display',
            'description' => 'Customer-facing POS display',
            'start_url' => '/customer-display',
            'scope' => '/customer-display',
            'display' => 'fullscreen',
            'orientation' => 'landscape',
            'background_color' => '#0b0c10',
            'theme_color' => '#0b0c10',
        ],
        'portal' => [
            'suffix' => 'Portal',
            'short_name' => 'Portal',
            'description' => 'Client Portal',
            'start_url' => '/portal',
            'scope' => '/portal',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f1f5f9',
            'theme_color' => '#2f3640',
        ],
    ];

    public function manifest(Request $request, $type = 'app')
    {
        $type = is_string($type) ? $type : 'app';
        $defaults = $this->surfaces[$type] ?? $this->surfaces['app'];

        // The storefront base path is configurable (Store Settings → Store URL);
        // keep the installed store PWA pointing at the live location.
        if ($type === 'store') {
            $storeBase = '/'.ltrim(store_path(), '/');
            $defaults['start_url'] = $storeBase === '/' ? '/' : rtrim($storeBase, '/');
            $defaults['scope'] = $defaults['start_url'];
        }
        $setting = $this->setting();
        $isApp = $type === 'app';

        $brand = $this->brandName($setting);
        $shortBrand = $this->shortBrandName($setting, $brand);

        if ($isApp) {
            $name = $brand;
            $shortName = $shortBrand;
        } else {
            // "Acme" + "Portal". Built from the short brand, since the full
            // name may carry a tagline ("Acme | Inventory With POS") that reads
            // badly with a suffix appended. The storefront keeps its own name
            // when one is configured, since it is customer-facing branding.
            $name = $this->withSuffix($this->surfaceBrand($type, $shortBrand), $defaults['suffix']);
            $shortName = $defaults['short_name'];
        }

        // A company's own desktop app (2026-11-20, user: "Murano - (Nombre de la Compañía) con su logo de icono"):
        // ?company=ID -> its name and its logo as the icon, opening the staff app.
        $company = $isApp && $request->filled('company')
            ? \Illuminate\Support\Facades\DB::table('companies')->where('id', (int) $request->company)->whereNull('deleted_at')->first(['id', 'name'])
            : null;
        if ($company) {
            $name = 'Murano - '.$company->name;
            $shortName = mb_substr($company->name, 0, 24);
        }

        $manifest = [
            'name' => $name,
            'short_name' => $shortName,
            'description' => $isApp
                ? ($this->value($setting, 'pwa_description') ?: $name)
                : $defaults['description'],
            'start_url' => $isApp
                ? ($this->value($setting, 'pwa_start_url') ?: $defaults['start_url'])
                : $defaults['start_url'],
            'scope' => $defaults['scope'],
            'display' => $isApp
                ? ($this->value($setting, 'pwa_display') ?: $defaults['display'])
                : $defaults['display'],
            'orientation' => $isApp
                ? ($this->value($setting, 'pwa_orientation') ?: $defaults['orientation'])
                : $defaults['orientation'],
            'background_color' => $isApp
                ? ($this->value($setting, 'pwa_background_color') ?: $defaults['background_color'])
                : $defaults['background_color'],
            'theme_color' => $isApp
                ? ($this->value($setting, 'pwa_theme_color') ?: $defaults['theme_color'])
                : $defaults['theme_color'],
            'lang' => 'en',
            'dir' => 'ltr',
            'icons' => $company ? $this->companyIcons((int) $company->id) : $this->icons(),
        ];
        if ($company) {
            $manifest['id'] = '/next/?company='.$company->id;
            $manifest['start_url'] = '/next/';
            $manifest['scope'] = '/';
        }

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'no-cache',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The configured PWA name, falling back to the app name and finally the
     * product default — the same chain the manifest route used before.
     */
    protected function brandName(?Setting $setting): string
    {
        $name = $this->value($setting, 'pwa_name')
            ?: $this->value($setting, 'app_name');

        return $name !== '' ? $name : 'KcolTech - Murano';
    }

    /**
     * short_name must stay brief for app launchers: use the configured value,
     * else the part before a "|" separator (e.g. "KcolTech - Murano | Ultimate Inventory
     * With POS" -> "KcolTech - Murano").
     */
    protected function shortBrandName(?Setting $setting, string $brand): string
    {
        $short = $this->value($setting, 'pwa_short_name');
        if ($short !== '') {
            return $short;
        }

        $short = trim(explode('|', $brand)[0]);

        return $short !== '' ? $short : $brand;
    }

    /**
     * The storefront is customer-facing, so it prefers the online store's own
     * name over the back-office brand when one is set.
     */
    protected function surfaceBrand(string $type, string $brand): string
    {
        if ($type !== 'store') {
            return $brand;
        }

        try {
            $storeName = trim((string) (StoreSetting::current()->store_name ?? ''));
            if ($storeName !== '') {
                return $storeName;
            }
        } catch (\Throwable $e) {
            // Store module tables may not exist; fall back to the brand.
        }

        return $brand;
    }

    /**
     * "Acme" + "Store" => "Acme Store", but a brand that already ends with the
     * suffix is left alone so it doesn't read "Acme Store Store".
     */
    protected function withSuffix(string $brand, string $suffix): string
    {
        $brand = trim($brand);
        if ($brand === '') {
            return $suffix;
        }
        if (strcasecmp(substr($brand, -strlen($suffix)), $suffix) === 0) {
            return $brand;
        }

        return $brand.' '.$suffix;
    }

    /** The company's logo as square PNG icons (pwaIcon below); the default icons when it has no logo. */
    protected function companyIcons(int $companyId): array
    {
        if (! $this->companyLogo($companyId)) {
            return $this->icons();
        }
        $out = [];
        foreach ([192, 512] as $size) {
            foreach (['any', 'maskable'] as $purpose) {
                $out[] = ['src' => "/pwa-icon/$companyId/$size.png", 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => $purpose];
            }
        }

        return $out;
    }

    /** files/... path of the company's logo, when the file is there. */
    protected function companyLogo(int $companyId): ?string
    {
        $logo = \Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->value('logo')
            ?: \Illuminate\Support\Facades\DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('logo');
        if (! $logo) {
            return null;
        }
        $path = public_path(ltrim(str_starts_with($logo, 'files/') || str_starts_with($logo, 'images/') ? $logo : 'images/'.$logo, '/'));

        return is_file($path) ? $path : null;
    }

    /** GET /pwa-icon/{company}/{size}.png: the logo centred on a white square (cached next to the company's files). */
    public function pwaIcon(int $company, int $size)
    {
        abort_unless(in_array($size, [192, 512], true), 404);
        $logo = $this->companyLogo($company);
        abort_unless($logo, 404);
        $dir = public_path("files/company_$company/pwa");
        $file = "$dir/icon-$size.png";
        if (! is_file($file) || filemtime($file) < filemtime($logo)) {
            @mkdir($dir, 0775, true);
            $src = @imagecreatefromstring((string) file_get_contents($logo));
            abort_unless($src, 404);
            $img = imagecreatetruecolor($size, $size);
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            $w = imagesx($src);
            $h = imagesy($src);
            $box = (int) round($size * 0.78);                     // room for the maskable safe zone
            $scale = min($box / $w, $box / $h);
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
            imagecopyresampled($img, $src, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
            imagepng($img, $file);
            imagedestroy($img);
            imagedestroy($src);
        }

        return response()->file($file, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }

    protected function icons(): array
    {
        $icon192 = $this->iconUrl(192);
        $icon512 = $this->iconUrl(512);

        return [
            ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }

    /**
     * Icons live on disk (public/pwa_images), not in the DB. The filemtime
     * query makes a re-uploaded icon reach installed apps instead of being
     * served from the browser's manifest cache.
     */
    protected function iconUrl(int $size): string
    {
        $relative = '/pwa_images/pwa-icon-'.$size.'.png';
        $absolute = public_path($relative);

        return is_file($absolute)
            ? $relative.'?v='.filemtime($absolute)
            : $relative;
    }

    protected function setting(): ?Setting
    {
        try {
            return Setting::first();
        } catch (\Throwable $e) {
            // Settings table may be unavailable (fresh install / setup wizard).
            return null;
        }
    }

    /**
     * Read a settings column defensively: it may be missing on installs that
     * have not run the PWA migration yet.
     */
    protected function value(?Setting $setting, string $column): string
    {
        return trim((string) ($setting->{$column} ?? ''));
    }
}
