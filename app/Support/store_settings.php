<?php

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Cache;

if (! function_exists('store_settings')) {
    function store_settings(): StoreSetting
    {
        return Cache::remember('store_settings', 600, function () {
            return StoreSetting::query()->first() ?? new StoreSetting;
        });
    }
}

if (! function_exists('store_reserved_paths')) {
    /**
     * First URL segments the storefront base path may never claim: they belong
     * to the admin panel / auth / system surfaces and must keep working even
     * when the store runs from the root domain.
     */
    function store_reserved_paths(): array
    {
        return [
            'api', 'setup', 'update', 'system-update', 'password', 'login', 'logout',
            'next', 'dashboard-next', 'portal', 'recruit', 'api-docs', 'csrf-token',
            'session', 'invoice', 'customer-display', 'quickbooks', 'google-calendar',
            'pwa', 'manifest.webmanifest', 'sitemap.xml', 'robots.txt', 'sw.js',
            'offline.html', 'storage', 'vendor', 'images', 'css', 'js', 'fonts',
            'pwa_images', 'customer',
        ];
    }
}

if (! function_exists('store_sanitize_path')) {
    /**
     * Normalize + validate a storefront base path. Returns the clean path
     * ("shop", "my/shop") or null when the value is empty/invalid/reserved.
     */
    function store_sanitize_path($raw): ?string
    {
        $path = strtolower(trim((string) $raw));
        $path = str_replace('\\', '/', $path);
        // No query strings, fragments or encoded tricks; collapse duplicate slashes.
        $path = trim(preg_replace('#/+#', '/', $path), "/ \t\n\r\0\x0B");

        if ($path === '' || strlen($path) > 100 || str_contains($path, '?') || str_contains($path, '#')) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $segment)) {
                return null;
            }
        }

        if (in_array(explode('/', $path)[0], store_reserved_paths(), true)) {
            return null;
        }

        return $path;
    }
}

if (! function_exists('store_path')) {
    /**
     * The storefront base path, without slashes: 'online_store' by default,
     * the configured custom path, or '' when "use root domain" is enabled.
     * Safe to call before the DB is ready (falls back to the default).
     */
    function store_path(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $cfg = ['root' => false, 'path' => null];
        try {
            $cfg = Cache::remember('store_url_config', 600, function () {
                $s = StoreSetting::query()->first();

                return [
                    'root' => (bool) ($s->store_use_root_domain ?? false),
                    'path' => $s->store_url_path ?? null,
                ];
            });
        } catch (\Throwable $e) {
            // Not installed yet / cache or DB unavailable: keep the default.
        }

        if (! empty($cfg['root'])) {
            return $resolved = '';
        }

        return $resolved = (store_sanitize_path($cfg['path'] ?? null) ?? 'online_store');
    }
}

if (! function_exists('store_path_to')) {
    /**
     * App-relative path for a storefront page: store_path_to('shop') =>
     * "online_store/shop", "shop/shop" (custom path) or "shop" (root mode).
     */
    function store_path_to(string $rel = ''): string
    {
        return trim(store_path().'/'.ltrim($rel, '/'), '/');
    }
}

if (! function_exists('store_base_url')) {
    /** Absolute URL of the storefront home under the configured base path. */
    function store_base_url(string $rel = ''): string
    {
        return url('/'.store_path_to($rel));
    }
}

if (! function_exists('is_store_request')) {
    /**
     * Whether a request targets the storefront. Path-based on purpose: it is
     * used by global middleware that runs before routing. In root-domain mode
     * everything that is not a reserved system prefix belongs to the store.
     */
    function is_store_request(\Illuminate\Http\Request $request): bool
    {
        $path = store_path();
        if ($path !== '') {
            return $request->is($path) || $request->is($path.'/*');
        }

        // Root mode: the customer auth pages live under /customer/* (reserved
        // only so it cannot be chosen as a custom base path) — they are store
        // requests and must use the store session cookie.
        if ($request->is('customer') || $request->is('customer/*')) {
            return true;
        }

        foreach (store_reserved_paths() as $reserved) {
            if ($request->is($reserved) || $request->is($reserved.'/*')) {
                return false;
            }
        }

        return true;
    }
}

if (! function_exists('store_sw_url')) {
    /**
     * Service-worker URL carrying the storefront base path, so sw.js can keep
     * caching the store shell separately from the admin shell ('/' = root mode).
     */
    function store_sw_url(): string
    {
        $path = store_path();

        return '/sw.js?store_base='.rawurlencode($path === '' ? '/' : '/'.$path);
    }
}

if (! function_exists('store_url_config_clear')) {
    /** Forget the cached URL config (call after saving store settings). */
    function store_url_config_clear(): void
    {
        try {
            Cache::forget('store_url_config');
            Cache::forget('store_settings');
        } catch (\Throwable $e) {
            // cache store unavailable — per-request fallback still applies
        }
    }
}

if (! function_exists('store_currency')) {
    /**
     * Multi-Currency: the currency the storefront displays right now
     * (['id','symbol','code','rate','is_base']); base currency, rate 1 when
     * the module is off or nothing is selected.
     */
    function store_currency(): array
    {
        return \App\Services\StoreCurrencyService::active();
    }
}

if (! function_exists('store_money')) {
    /**
     * Format a BASE-currency amount in the active store currency, matching
     * the storefront's historical "{symbol}{amount}" rendering. Pass an
     * explicit currency array (e.g. StoreCurrencyService::forDocument($order))
     * for order pages that must use the order's snapshotted rate.
     */
    function store_money($baseAmount, ?array $currency = null): string
    {
        $c = $currency ?? store_currency();
        $dec = \App\utils\helpers::price_decimals();

        return $c['symbol'].number_format(((float) $baseAmount) * (($c['rate'] ?? 1) ?: 1), $dec, '.', ',');
    }
}

if (! function_exists('store_locales')) {
    /**
     * Locales the storefront can actually render (the ones with a
     * resources/lang/<locale> bundle), keyed by locale => display name.
     */
    function store_locales(): array
    {
        return \App\Http\Middleware\SetLocale::SUPPORTED;
    }
}

if (! function_exists('store_date')) {
    /**
     * Format a date for the storefront in the visitor's language
     * ("May 21, 2026" / "21 may 2026" / "٢١ مايو ٢٠٢٦") instead of the
     * hardcoded English format the store pages used to print.
     */
    function store_date($date, string $format = 'll'): string
    {
        if (empty($date)) {
            return '';
        }

        $c = $date instanceof \Carbon\CarbonInterface ? $date : \Carbon\Carbon::parse($date);

        return $c->locale(app()->getLocale())->isoFormat($format);
    }
}

if (! function_exists('store_address_lines')) {
    /**
     * An address as clean display lines: "city, state" joined properly, the zip
     * kept beside them, blanks dropped entirely.
     *
     * Order emails used to interpolate the parts inline, where Blade eats the
     * newline after a trailing @endif — so "31160" and "México" arrived glued
     * together as "31160México" and empty fields left stray separators.
     *
     * @param  array<string, string|null>  $parts  name/address/city/state/zip/country
     * @return array<int, string>
     */
    function store_address_lines(array $parts): array
    {
        $clean = fn ($v) => trim((string) ($v ?? ''));

        // "City, State 31160" — only the pieces that are actually present.
        $locality = implode(', ', array_filter([
            $clean($parts['city'] ?? null),
            $clean($parts['state'] ?? null),
        ], fn ($v) => $v !== ''));

        $zip = $clean($parts['zip'] ?? null);
        if ($zip !== '') {
            $locality = $locality === '' ? $zip : $locality.' '.$zip;
        }

        return array_values(array_filter([
            $clean($parts['name'] ?? null),
            $clean($parts['address'] ?? null),
            $locality,
            $clean($parts['country'] ?? null),
        ], fn ($v) => $v !== ''));
    }
}
