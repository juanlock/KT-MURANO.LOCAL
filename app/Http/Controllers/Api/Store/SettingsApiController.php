<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\StoreSetting;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;

class SettingsApiController extends Controller
{
    /**
     * Return settings (create sane defaults if missing).
     * Also migrates old home_collections -> homepage_lineup (once), if present.
     */
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);
        $s = StoreSetting::first();
        $setting = \App\Models\Setting::first();
        $warehouses = Warehouse::whereNull('deleted_at')->get(['id', 'name']);
        $currencies = Currency::whereNull('deleted_at')->get(['id', 'name', 'symbol']);
        $default_currency_id = $setting?->Currency?->id ?? null;

        if (! $s) {
            $s = StoreSetting::create([
                'enabled' => 1,
                'store_name' => 'StoreX',
                'primary_color' => '#6c5ce7',
                'secondary_color' => '#00c2ff',
                'font_family' => 'Arial, sans-serif',
                'favicon_path' => 'images/store/favicon.ico',
                'hero_image_path' => 'images/store/hero_image.jpg',
                'language' => 'en',
                'currency_code' => $default_currency_id,
                'default_warehouse_id' => $setting?->warehouse_id ?: (Warehouse::first()?->id ?? null),
                'allow_overselling' => true,
                'hide_out_of_stock' => false,
                'hide_prices_for_guests' => false,

                'contact_email' => 'info@storex.test',
                'contact_phone' => '+1234567890',
                'contact_address' => '123 Main St, Sample City',

                'hero_title' => 'Sell online & in-store',
                'hero_subtitle' => 'Beautiful storefront. Synced inventory.',
                'seo_meta_title' => 'Online Store',
                'seo_meta_description' => 'A modern online storefront powered by your POS & Inventory system.',

                'topbar_text_left' => '🚚 Free shipping on orders over $99',
                'topbar_text_right' => '🔥 Summer deals are live!',
                'footer_text' => 'A beautiful demo storefront paired with your POS & Inventory system.',

                'social_links' => json_encode([
                    ['platform' => 'facebook',  'url' => 'https://facebook.com'],
                    ['platform' => 'instagram', 'url' => 'https://instagram.com'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

                'homepage_lineup' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'homepage_layout' => 'default',

            ]);
        }

        // ---- One-time migration: old home_collections -> new homepage_lineup
        // Accept old shape: [{collection_id, title, limit, visible, sort_order}]
        if (empty($s->homepage_lineup) && ! empty($s->home_collections)) {
            $migrated = $this->migrateHomeCollectionsToHomepageLineup($s->home_collections);
            if (! empty($migrated)) {
                $s->homepage_lineup = $migrated;
                $s->save();
            }
        }

        $s->default_currency_id = $default_currency_id;
        // Locales the storefront can render, for the default-language select
        // and the per-language copy editors.
        $s->store_locales = store_locales();
        $s->translatable_text_fields = StoreSetting::TRANSLATABLE_TEXT;
        $s->text_translations = (object) ($s->text_translations ?? []);
        // The secrets themselves are $hidden on the model; the UI only needs
        // to know whether one is stored (leave-blank-keeps behaviour).
        $s->paypal_secret_set = trim((string) $s->paypal_client_secret) !== '';
        $s->paystack_secret_set = trim((string) $s->paystack_secret_key) !== '';
        $s->flutterwave_secret_set = trim((string) $s->flutterwave_secret_key) !== '';
        $s->flutterwave_hash_set = trim((string) $s->flutterwave_secret_hash) !== '';
        $s->razorpay_secret_set = trim((string) $s->razorpay_key_secret) !== '';
        $s->razorpay_webhook_secret_set = trim((string) $s->razorpay_webhook_secret) !== '';
        $s->bkash_secret_set = trim((string) $s->bkash_app_secret) !== '';
        $s->bkash_password_set = trim((string) $s->bkash_password) !== '';
        $s->sslcommerz_password_set = trim((string) $s->sslcommerz_store_password) !== '';

        // Where the storefront currently lives (custom path / root domain),
        // plus the app origin so the UI can preview the URL while editing.
        $s->store_effective_url = store_base_url();
        $s->app_origin = rtrim(url('/'), '/');

        $pendingCustomersCount = \App\Models\EcommerceClient::where('status', 0)
            ->whereNull('deleted_at')
            ->count();

        // Resolve labels for the curated "You may also like" products so the admin
        // picker can render the already-selected chips (order preserved).
        $curatedProducts = [];
        $lineup = is_array($s->homepage_lineup)
            ? $s->homepage_lineup
            : (json_decode((string) $s->homepage_lineup, true) ?: []);
        foreach ($lineup as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'you_may_like') {
                continue;
            }
            $ids = array_values(array_filter(array_map('intval', (array) ($item['product_ids'] ?? []))));
            if ($ids) {
                $curatedProducts = \App\Models\Product::whereIn('id', $ids)
                    ->orderByRaw('FIELD(id, '.implode(',', $ids).')')
                    ->get(['id', 'name'])
                    ->all();
            }
            break;
        }

        return response()->json([
            'settings' => $s,
            'warehouses' => $warehouses,
            'currencies' => $currencies,
            'pending_customers_count' => $pendingCustomersCount,
            'curated_products' => $curatedProducts,
        ]);
    }

    /**
     * Update settings + handle image uploads.
     */
    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        // --- Normalize boolean to 0/1 so in:0,1 passes ---
        if ($request->has('enabled')) {
            $request->merge([
                'enabled' => (int) filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        foreach (['registration_enabled', 'require_invite_code', 'require_admin_approval', 'require_email_verification', 'allow_cancellations', 'auto_approve_reviews', 'cookie_consent_enabled', 'wallet_enabled', 'wallet_allow_negative', 'wallet_withdrawal_enabled', 'payment_cod_enabled', 'payment_mobile_money_enabled', 'payment_stripe_enabled', 'payment_gcash_enabled', 'payment_bank_transfer_enabled', 'payment_cash_on_pickup_enabled', 'paypal_enabled', 'paypal_test_mode', 'paystack_enabled', 'flutterwave_enabled', 'razorpay_enabled', 'bkash_enabled', 'bkash_sandbox', 'sslcommerz_enabled', 'sslcommerz_sandbox', 'store_use_root_domain'] as $regField) {
            if ($request->has($regField)) {
                $request->merge([
                    $regField => (int) filter_var($request->input($regField), FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }
        if ($request->has('allow_overselling')) {
            $request->merge([
                'allow_overselling' => (int) filter_var($request->input('allow_overselling'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('hide_out_of_stock')) {
            $request->merge([
                'hide_out_of_stock' => (int) filter_var($request->input('hide_out_of_stock'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('hide_prices_for_guests')) {
            $request->merge([
                'hide_prices_for_guests' => (int) filter_var($request->input('hide_prices_for_guests'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('show_stock')) {
            $request->merge([
                'show_stock' => (int) filter_var($request->input('show_stock'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('store_all_warehouses')) {
            $request->merge([
                'store_all_warehouses' => (int) filter_var($request->input('store_all_warehouses'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        // Electronics theme slide/banner uploads arrive as
        // theme_image__<list>__<index>; validate each one like hero_image.
        $themeImageRules = [];
        foreach (array_keys($request->allFiles()) as $fileKey) {
            if (preg_match('/^theme_image__(?:[a-z_]+__)?(slides|banners|tiles)__(\d{1,2})$/', $fileKey)) {
                $themeImageRules[$fileKey] = 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192';
            }
        }
        $request->validate($themeImageRules);

        // --- Validation ---
        $data = $request->validate([
            'enabled' => 'nullable|in:0,1',
            'registration_enabled' => 'nullable|in:0,1',
            'require_invite_code' => 'nullable|in:0,1',
            'require_admin_approval' => 'nullable|in:0,1',
            'require_email_verification' => 'nullable|in:0,1',
            'allow_cancellations' => 'nullable|in:0,1',
            'return_window_days' => 'nullable|integer|min:0|max:365',
            'auto_approve_reviews' => 'nullable|in:0,1',
            'cookie_consent_enabled' => 'nullable|in:0,1',

            // E-Wallet
            'wallet_enabled' => 'nullable|in:0,1',
            'wallet_allow_negative' => 'nullable|in:0,1',
            'wallet_refund_destination' => 'nullable|in:wallet,original',
            'wallet_withdrawal_enabled' => 'nullable|in:0,1',
            'wallet_min_withdrawal' => 'nullable|numeric|min:0',

            // Storefront checkout payment methods
            'payment_cod_enabled' => 'nullable|in:0,1',
            'payment_mobile_money_enabled' => 'nullable|in:0,1',
            // Card payments can be switched off without clearing the Stripe keys.
            'payment_stripe_enabled' => 'nullable|in:0,1',

            // Manual/offline methods: GCash, bank transfer, cash on pickup
            'payment_gcash_enabled' => 'nullable|in:0,1',
            'gcash_account_name' => 'nullable|string|max:150',
            'gcash_account_number' => 'nullable|string|max:60',
            'gcash_instructions' => 'nullable|string|max:1000',
            'payment_bank_transfer_enabled' => 'nullable|in:0,1',
            'bank_name' => 'nullable|string|max:150',
            'bank_account_name' => 'nullable|string|max:150',
            'bank_account_number' => 'nullable|string|max:60',
            'bank_branch' => 'nullable|string|max:150',
            'bank_instructions' => 'nullable|string|max:1000',
            'payment_cash_on_pickup_enabled' => 'nullable|in:0,1',
            'pickup_instructions' => 'nullable|string|max:1000',

            // PayPal gateway (online-store checkout)
            'paypal_enabled' => 'nullable|in:0,1',
            'paypal_client_id' => 'nullable|string|max:191',
            'paypal_client_secret' => 'nullable|string|max:500',
            'paypal_test_mode' => 'nullable|in:0,1',
            'paypal_webhook_id' => 'nullable|string|max:100',

            // Paystack gateway (online-store checkout; test/live per key prefix)
            'paystack_enabled' => 'nullable|in:0,1',
            'paystack_public_key' => 'nullable|string|max:191',
            'paystack_secret_key' => 'nullable|string|max:500',

            // Flutterwave gateway (v3; test/live per key prefix)
            'flutterwave_enabled' => 'nullable|in:0,1',
            'flutterwave_public_key' => 'nullable|string|max:191',
            'flutterwave_secret_key' => 'nullable|string|max:500',
            'flutterwave_secret_hash' => 'nullable|string|max:191',

            // Razorpay gateway (Payment Links; test/live per key prefix)
            'razorpay_enabled' => 'nullable|in:0,1',
            'razorpay_key_id' => 'nullable|string|max:191',
            'razorpay_key_secret' => 'nullable|string|max:500',
            'razorpay_webhook_secret' => 'nullable|string|max:191',

            // bKash gateway (Tokenized Checkout; sandbox/live switch)
            'bkash_enabled' => 'nullable|in:0,1',
            'bkash_app_key' => 'nullable|string|max:191',
            'bkash_app_secret' => 'nullable|string|max:500',
            'bkash_username' => 'nullable|string|max:191',
            'bkash_password' => 'nullable|string|max:500',
            'bkash_sandbox' => 'nullable|in:0,1',

            // SSLCommerz gateway (hosted checkout; sandbox/live switch)
            'sslcommerz_enabled' => 'nullable|in:0,1',
            'sslcommerz_store_id' => 'nullable|string|max:191',
            'sslcommerz_store_password' => 'nullable|string|max:500',
            'sslcommerz_sandbox' => 'nullable|in:0,1',

            'allow_overselling' => 'nullable|in:0,1',
            'hide_out_of_stock' => 'nullable|in:0,1',
            'hide_prices_for_guests' => 'nullable|in:0,1',
            'show_stock' => 'nullable|in:0,1',

            'store_name' => 'nullable|string|max:190',
            'theme' => 'nullable|string|in:default,real_estate,'.implode(',', \App\Support\StoreThemes::SKINS),
            // Per-theme presentation options (JSON map keyed by theme).
            'theme_options' => 'nullable',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'font_family' => 'nullable|string|max:100',

            'language' => 'nullable|string|max:10',
            'default_currency_id' => 'nullable|integer',
            // Storefront display currency — distinct from default_currency_id,
            // which sets the accounting base every amount is stored in.
            'display_currency_id' => 'nullable|integer',
            // Per-language copy: {field: {locale: text}} for TRANSLATABLE_TEXT.
            'text_translations' => 'nullable',
            'default_warehouse_id' => 'nullable|integer',
            'warehouse_ids' => 'nullable',
            'store_all_warehouses' => 'nullable|in:0,1',

            'contact_email' => 'nullable|email|max:190',
            'contact_phone' => 'nullable|string|max:50',
            'contact_address' => 'nullable|string|max:255',

            'hero_title' => 'nullable|string|max:255',
            'hero_subtitle' => 'nullable|string|max:1000',

            'seo_meta_title' => 'nullable|string|max:255',
            'seo_meta_description' => 'nullable|string|max:1000',
            'store_domain' => 'nullable|string|max:190',
            'seo_title_template' => 'nullable|string|max:190',

            // Store URL configuration (custom base path / root-domain mode)
            'store_url_path' => 'nullable|string|max:100',
            'store_use_root_domain' => 'nullable|in:0,1',

            'topbar_text_left' => 'nullable|string|max:190',
            'topbar_text_right' => 'nullable|string|max:190',
            'footer_text' => 'nullable|string|max:255',

            'social_links' => 'nullable',
            'homepage_lineup' => 'nullable',
            'homepage_layout' => 'nullable|string|in:default',
            'home_collections' => 'nullable',

            'custom_css' => 'nullable|string',
            'custom_js' => 'nullable|string',

            // Laravel 12: image rule excludes SVG by default; using file + mimes
            'logo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
            'favicon' => 'nullable|file|mimes:jpg,jpeg,png,webp,ico|mimetypes:image/png,image/jpeg,image/webp,image/x-icon,image/vnd.microsoft.icon|max:2048',
            'hero_image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'gcash_qr' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        // --- Store URL path: normalize + reject unsafe or reserved paths. The
        // default 'online_store' is stored as NULL so untouched installs keep
        // behaving exactly as before this setting existed. ---
        if (array_key_exists('store_url_path', $data)) {
            $raw = trim((string) $data['store_url_path'], "/ \t\n\r");
            if ($raw === '' || strtolower($raw) === 'online_store') {
                $data['store_url_path'] = null;
            } else {
                $clean = store_sanitize_path($raw);
                if ($clean === null) {
                    return response()->json([
                        'message' => 'The store URL path is invalid or conflicts with a system route.',
                        'errors' => ['store_url_path' => ['Use letters, numbers, dashes or underscores (e.g. "shop"), avoiding reserved system paths.']],
                    ], 422);
                }
                $data['store_url_path'] = $clean;
            }
        }

        $urlConfigTouched = array_key_exists('store_url_path', $data) || array_key_exists('store_use_root_domain', $data);

        // Gateway secrets: blank means "keep the stored one" (never echoed back).
        foreach (['paypal_client_secret', 'paystack_secret_key', 'flutterwave_secret_key', 'flutterwave_secret_hash', 'razorpay_key_secret', 'razorpay_webhook_secret', 'bkash_app_secret', 'bkash_password', 'sslcommerz_store_password'] as $secretField) {
            if (array_key_exists($secretField, $data) && trim((string) $data[$secretField]) === '') {
                unset($data[$secretField]);
            }
        }

        // --- Online-store warehouses: "all" is stored as NULL, otherwise the
        // validated id list; default_warehouse_id stays in sync as a legacy
        // fallback for older code paths. ---
        if ($request->has('warehouse_ids') || $request->has('store_all_warehouses')) {
            $ids = $data['warehouse_ids'] ?? [];
            if (is_string($ids)) {
                $ids = json_decode($ids, true) ?: [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
            $ids = empty($ids) ? [] : Warehouse::whereIn('id', $ids)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! empty($data['store_all_warehouses']) || empty($ids)) {
                $data['warehouse_ids'] = null;
                $data['default_warehouse_id'] = $data['default_warehouse_id']
                    ?? StoreSetting::query()->value('default_warehouse_id')
                    ?? Warehouse::whereNull('deleted_at')->value('id');
            } else {
                $data['warehouse_ids'] = $ids;
                $data['default_warehouse_id'] = $ids[0];
            }
        }
        unset($data['store_all_warehouses']);

        // --- Decode JSON fields ---
        foreach (['social_links', 'homepage_lineup', 'home_collections'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $decoded = json_decode($data[$key], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data[$key] = $decoded;
                }
            }
        }

        // --- Normalize unified lineup ---
        if (array_key_exists('homepage_lineup', $data)) {
            $data['homepage_lineup'] = $this->normalizeHomepageLineup($data['homepage_lineup']);
        }

        // --- Migrate legacy collections if needed ---
        if (! empty($data['home_collections']) && empty($data['homepage_lineup'])) {
            $data['homepage_lineup'] = $this->migrateHomeCollectionsToHomepageLineup($data['home_collections']);
        }
        unset($data['home_collections']); // don't persist legacy field

        // --- Ensure model exists ---
        $s = StoreSetting::first() ?: new StoreSetting;

        // --- Ensure storage directory exists ---
        $targetDir = public_path('images/store');
        if (! File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        // ============================
        // IMAGE UPLOAD HANDLERS
        // ============================

        // --- LOGO (200x200 max) ---
        if ($request->hasFile('logo')) {
            if ($s->logo_path && File::exists(public_path($s->logo_path))) {
                File::delete(public_path($s->logo_path));
            }

            $ext = strtolower($request->file('logo')->guessExtension() ?: 'png');
            $filename = (string) Str::uuid().'.'.$ext;

            Image::make($request->file('logo')->getRealPath())
                ->resize(200, 200, function ($c) {
                    $c->aspectRatio();
                    $c->upsize();
                })
                ->encode($ext, 85)
                ->save($targetDir.'/'.$filename);

            $data['logo_path'] = 'images/store/'.$filename;
        }

        // --- FAVICON ---
        if ($request->hasFile('favicon')) {
            if ($s->favicon_path && File::exists(public_path($s->favicon_path))) {
                File::delete(public_path($s->favicon_path));
            }

            $file = $request->file('favicon');
            $mime = $file->getMimeType() ?: '';
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');

            if ($ext === 'ico' || in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon'], true)) {
                $filename = (string) Str::uuid().'.ico';
                $file->move($targetDir, $filename);
            } else {
                $filename = (string) Str::uuid().'.png';
                Image::make($file->getRealPath())
                    ->fit(64, 64)
                    ->encode('png')
                    ->save($targetDir.'/'.$filename);
            }

            $data['favicon_path'] = 'images/store/'.$filename;
        }

        // --- GCASH QR (600x600 max) ---
        if ($request->hasFile('gcash_qr')) {
            if ($s->gcash_qr_path && File::exists(public_path($s->gcash_qr_path))) {
                File::delete(public_path($s->gcash_qr_path));
            }

            $ext = strtolower($request->file('gcash_qr')->guessExtension() ?: 'png');
            $filename = (string) Str::uuid().'.'.$ext;

            Image::make($request->file('gcash_qr')->getRealPath())
                ->resize(600, 600, function ($c) {
                    $c->aspectRatio();
                    $c->upsize();
                })
                ->encode($ext, 88)
                ->save($targetDir.'/'.$filename);

            $data['gcash_qr_path'] = 'images/store/'.$filename;
        }

        // --- HERO IMAGE (1600x800 max) ---
        if ($request->hasFile('hero_image')) {
            if ($s->hero_image_path && File::exists(public_path($s->hero_image_path))) {
                File::delete(public_path($s->hero_image_path));
            }

            $ext = strtolower($request->file('hero_image')->guessExtension() ?: 'jpg');
            $filename = (string) Str::uuid().'.'.$ext;

            Image::make($request->file('hero_image')->getRealPath())
                ->resize(1600, 800, function ($c) {
                    $c->aspectRatio();
                    $c->upsize();
                })
                ->encode($ext, 82)
                ->save($targetDir.'/'.$filename);

            $data['hero_image_path'] = 'images/store/'.$filename;
        }

        // --- THEME OPTIONS (skin themes: electronics, toys, …) ---
        // theme_options = {<theme>: {...}}; each theme's support class sanitizes
        // its own map. Uploaded images arrive as theme_image__<theme>__<list>__<i>
        // (legacy electronics form: theme_image__<list>__<i>).
        if ($request->has('theme_options')) {
            $existing = is_array($s->theme_options) ? $s->theme_options : [];
            $incoming = $request->input('theme_options');
            if (is_string($incoming)) {
                $incoming = json_decode($incoming, true);
            }
            if (! is_array($incoming)) {
                $incoming = [];
            }
            // Legacy payload without a theme key = electronics options.
            if (! array_intersect(array_keys($incoming), \App\Support\StoreThemes::SKINS)) {
                $incoming = [\App\Support\ElectronicsTheme::KEY => $incoming];
            }

            $themeDir = public_path('images/store/theme');
            foreach (\App\Support\StoreThemes::SKINS as $themeKey) {
                if (! array_key_exists($themeKey, $incoming)) {
                    continue;
                }
                $clean = \App\Support\StoreThemes::sanitize($themeKey, $incoming[$themeKey]);

                foreach ($request->allFiles() as $fileKey => $file) {
                    if (! $file || ! preg_match('/^theme_image__(?:([a-z_]+)__)?(slides|banners|tiles)__(\d{1,2})$/', $fileKey, $m)) {
                        continue;
                    }
                    $fileTheme = $m[1] !== '' ? $m[1] : \App\Support\ElectronicsTheme::KEY;
                    if ($fileTheme !== $themeKey) {
                        continue;
                    }
                    [$list, $idx] = [$m[2], (int) $m[3]];
                    if (! isset($clean[$list][$idx])) {
                        continue;
                    }
                    if (! File::exists($themeDir)) {
                        File::makeDirectory($themeDir, 0755, true);
                    }
                    $ext = strtolower($file->guessExtension() ?: 'jpg');
                    $filename = (string) Str::uuid().'.'.$ext;
                    Image::make($file->getRealPath())
                        ->resize(1600, 1000, function ($c) {
                            $c->aspectRatio();
                            $c->upsize();
                        })
                        ->encode($ext, 82)
                        ->save($themeDir.'/'.$filename);

                    $old = (string) ($clean[$list][$idx]['image'] ?? '');
                    if ($old !== '' && Str::startsWith($old, 'images/store/theme/') && File::exists(public_path($old))) {
                        File::delete(public_path($old));
                    }
                    $clean[$list][$idx]['image'] = 'images/store/theme/'.$filename;
                }

                $existing[$themeKey] = $clean;
            }
            $data['theme_options'] = $existing;
        } else {
            unset($data['theme_options']);
        }

        // ============================
        // CURRENCY HANDLING
        // ============================

        if (! empty($data['default_currency_id'])) {
            $currency = Currency::find($data['default_currency_id']);
            if ($currency) {
                $data['currency_code'] = $currency->symbol;

                $setting = \App\Models\Setting::first();
                if ($setting) {
                    $setting->currency_id = $currency->id;
                    $setting->save();
                }
            }
        }

        // ============================
        // SAVE CHANGES
        // ============================

        // A partial submit that never mentions the map must leave it alone.
        if ($request->has('text_translations')) {
            $data['text_translations'] = StoreSetting::cleanTextTranslations($data['text_translations'] ?? null);
        } else {
            unset($data['text_translations']);
        }

        $s->fill($data)->save();

        // store_settings() is cached for 10 minutes — drop it on every save so
        // changes (storefront default language/currency included) apply now.
        store_url_config_clear();

        // Store URL changed: drop the cached config so the new base path takes
        // effect on the next request, and rebuild Laravel's route cache if one
        // exists (the storefront prefix is baked into cached routes).
        if ($urlConfigTouched) {
            try {
                if (app()->routesAreCached()) {
                    \Illuminate\Support\Facades\Artisan::call('route:clear');
                }
            } catch (\Throwable $e) {
                // best effort — an admin can still run `php artisan route:clear`
            }
        }

        return response()->json($s->fresh());
    }

    /**
     * Return the storefront menus + link targets (pages, collections) for the editor.
     */
    public function menus(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $s = StoreSetting::first();
        $menus = $this->normalizeMenus(is_array($s?->menus) ? $s->menus : []);

        $pages = \App\Models\StorePage::orderBy('title')->get(['id', 'title', 'slug', 'published']);
        $collections = Collection::orderBy('title')->get(['id', 'title', 'slug'])
            ->map(fn ($c) => ['title' => $c->title ?: $c->slug, 'slug' => $c->slug])
            ->filter(fn ($c) => ! empty($c['slug']))
            ->values();

        return response()->json([
            'menus' => $menus,
            'pages' => $pages,
            'collections' => $collections,
            'locales' => store_locales(),
        ]);
    }

    /**
     * Persist the storefront menus.
     */
    public function updateMenus(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $request->validate(['menus' => 'nullable']);
        $menus = $this->normalizeMenus($data['menus'] ?? []);

        $s = StoreSetting::first() ?: new StoreSetting;
        $s->menus = $menus;
        $s->save();

        return response()->json(['menus' => $menus]);
    }

    /**
     * Normalize storefront menus to { header, footer_shop, footer_support } of
     * [{label, type, value}] items.
     */
    private function normalizeMenus($val): array
    {
        if (is_string($val)) {
            $val = json_decode($val, true) ?: [];
        }
        if (! is_array($val)) {
            $val = [];
        }

        $allowedTypes = ['home', 'shop', 'flash_sales', 'contact', 'wishlist', 'compare', 'page', 'collection', 'url'];
        $out = [];

        foreach (['header', 'footer_shop', 'footer_support'] as $loc) {
            $items = $val[$loc] ?? [];
            if (! is_array($items)) {
                $items = [];
            }
            $clean = [];
            foreach ($items as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $label = trim((string) ($it['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $type = (string) ($it['type'] ?? 'url');
                if (! in_array($type, $allowedTypes, true)) {
                    $type = 'url';
                }
                // Per-locale label; blanks fall back to `label` on the storefront.
                $labels = $it['label_translations'] ?? [];
                if (is_string($labels)) {
                    $labels = json_decode($labels, true);
                }
                $cleanLabels = [];
                foreach ((array) $labels as $locale => $text) {
                    if (isset(store_locales()[$locale]) && trim((string) $text) !== '') {
                        $cleanLabels[$locale] = mb_substr(trim((string) $text), 0, 80);
                    }
                }

                $clean[] = [
                    'label' => mb_substr($label, 0, 80),
                    'label_translations' => (object) $cleanLabels,
                    'type' => $type,
                    'value' => mb_substr(trim((string) ($it['value'] ?? '')), 0, 255),
                ];
                if (count($clean) >= 30) {
                    break;
                }
            }
            $out[$loc] = $clean;
        }

        return $out;
    }

    /**
     * Return only calendar-related settings (for System Settings → Calendar tab).
     */
    public function showCalendar(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);
        $s = Setting::first();
        if (! $s) {
            return response()->json([
                'google_calendar_connected' => false,
                'google_calendar_connect_url' => route('google_calendar.connect'),
                'google_calendar_disconnect_url' => route('google_calendar.disconnect'),
                'google_calendar_client_id' => null,
                'google_calendar_client_secret_set' => false,
                'google_calendar_redirect_uri' => null,
                'google_calendar_calendar_id' => null,
            ]);
        }
        return response()->json([
            'google_calendar_connected' => ! empty($s->google_calendar_refresh_token),
            'google_calendar_connect_url' => route('google_calendar.connect'),
            'google_calendar_disconnect_url' => route('google_calendar.disconnect'),
            'google_calendar_client_id' => $s->google_calendar_client_id,
            'google_calendar_client_secret_set' => ! empty($s->google_calendar_client_secret),
            'google_calendar_redirect_uri' => $s->google_calendar_redirect_uri,
            'google_calendar_calendar_id' => $s->google_calendar_calendar_id,
        ]);
    }

    /**
     * Update only calendar-related settings (credentials, calendar ID).
     */
    public function updateCalendar(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);
        $data = $request->validate([
            'google_calendar_client_id' => 'nullable|string|max:255',
            'google_calendar_client_secret' => 'nullable|string|max:65535',
            'google_calendar_redirect_uri' => 'nullable|string|max:512',
            'google_calendar_calendar_id' => 'nullable|string|max:255',
        ]);
        $s = Setting::first();
        if (! $s) {
            return response()->json(['error' => 'Settings record not found.'], 404);
        }
        // Do not overwrite client_secret with empty value (user may leave it blank to keep existing)
        if (array_key_exists('google_calendar_client_secret', $data) && $data['google_calendar_client_secret'] === '') {
            unset($data['google_calendar_client_secret']);
        }
        $s->fill($data)->save();
        return response()->json(['success' => true]);
    }

    /**
     * Normalize homepage_lineup to an array of items like:
     *  - {"type":"hero"}
     *  - {"type":"newsletter"}
     *  - {"type":"collection","slug":"best_sellers","limit":8,"layout":"grid","title_override":""}
     */
    private function normalizeHomepageLineup($val): array
    {
        if (! $val || ! is_array($val)) {
            return [];
        }

        $out = [];
        $seenCollections = [];
        $hasHero = false;
        $hasNewsletter = false;
        $hasPromoBanner = false;
        $hasCategories = false;
        $hasBestSellers = false;
        $hasYouMayLike = false;

        foreach ($val as $row) {
            if (! is_array($row) || empty($row['type'])) {
                continue;
            }
            $type = strtolower((string) $row['type']);

            if ($type === 'hero') {
                if ($hasHero) {
                    continue;
                }
                $out[] = ['type' => 'hero'];
                $hasHero = true;

                continue;
            }

            if ($type === 'newsletter') {
                if ($hasNewsletter) {
                    continue;
                }
                $out[] = ['type' => 'newsletter'];
                $hasNewsletter = true;

                continue;
            }

            if ($type === 'promo_banner') {
                if ($hasPromoBanner) {
                    continue;
                }
                $hasPromoBanner = true;
                $out[] = [
                    'type' => 'promo_banner',
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                    'subtitle' => isset($row['subtitle']) ? (string) $row['subtitle'] : '',
                    'button_text' => isset($row['button_text']) ? (string) $row['button_text'] : __('Shop Now'),
                    'link' => isset($row['link']) ? (string) $row['link'] : '',
                ];

                continue;
            }

            if ($type === 'categories') {
                if ($hasCategories) {
                    continue;
                }
                $hasCategories = true;
                $out[] = [
                    'type' => 'categories',
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                ];

                continue;
            }

            if ($type === 'best_sellers') {
                if ($hasBestSellers) {
                    continue;
                }
                $hasBestSellers = true;

                $limit = isset($row['limit']) ? (int) $row['limit'] : 8;
                if ($limit <= 0) {
                    $limit = 8;
                }

                $out[] = [
                    'type' => 'best_sellers',
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                    'limit' => $limit,
                ];

                continue;
            }

            if ($type === 'you_may_like') {
                if ($hasYouMayLike) {
                    continue;
                }
                $hasYouMayLike = true;

                $limit = isset($row['limit']) ? (int) $row['limit'] : 8;
                if ($limit <= 0) {
                    $limit = 8;
                }

                $ids = [];
                if (isset($row['product_ids']) && is_array($row['product_ids'])) {
                    foreach ($row['product_ids'] as $pid) {
                        $pid = (int) $pid;
                        if ($pid > 0) {
                            $ids[] = $pid;
                        }
                    }
                    $ids = array_values(array_slice(array_unique($ids), 0, 48));
                }

                $out[] = [
                    'type' => 'you_may_like',
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                    'limit' => $limit,
                    'product_ids' => $ids,
                ];

                continue;
            }

            if ($type === 'collection') {
                // Accept slug (preferred). Back-compat: accept "handle"
                $slug = isset($row['slug']) ? (string) $row['slug'] : (isset($row['handle']) ? (string) $row['handle'] : '');
                $slug = trim($slug);
                if ($slug === '') {
                    continue;
                }

                if (isset($seenCollections[$slug])) {
                    continue;
                } // de-dupe by slug
                $seenCollections[$slug] = true;

                $limit = isset($row['limit']) ? (int) $row['limit'] : 8;
                if ($limit <= 0) {
                    $limit = 8;
                }

                $layout = isset($row['layout']) && in_array($row['layout'], ['grid', 'carousel'], true)
                    ? $row['layout'] : 'grid';

                $titleOverride = isset($row['title_override']) ? (string) $row['title_override'] : '';

                $out[] = [
                    'type' => 'collection',
                    'slug' => $slug,
                    'limit' => $limit,
                    'layout' => $layout,
                    'title_override' => $titleOverride,
                ];
            }
        }

        return $out;
    }

    /**
     * Migrate legacy home_collections (by collection_id) to homepage_lineup (by slug).
     * Accepts rows like: [{collection_id, title, limit, visible, sort_order}]
     * Requires a Collection model with id/slug; missing slugs are skipped.
     */
    private function migrateHomeCollectionsToHomepageLineup($val): array
    {
        if (! $val || ! is_array($val)) {
            return [];
        }

        // Filter only visible rows and sort by sort_order
        $rows = array_values(array_filter($val, function ($r) {
            if (! is_array($r)) {
                return false;
            }
            if (empty($r['collection_id'])) {
                return false;
            }
            $visible = array_key_exists('visible', $r)
                ? filter_var($r['visible'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : true;

            return $visible !== false; // treat null as true
        }));

        usort($rows, function ($a, $b) {
            $sa = (int) ($a['sort_order'] ?? 9999);
            $sb = (int) ($b['sort_order'] ?? 9999);

            return $sa <=> $sb;
        });

        $ids = array_unique(array_map(fn ($r) => (int) $r['collection_id'], $rows));
        if (empty($ids)) {
            return [];
        }

        // Map IDs -> slugs (skip when model/table not available)
        $idToSlug = [];
        if (class_exists(Collection::class)) {
            $idToSlug = Collection::query()
                ->whereIn('id', $ids)
                ->pluck('slug', 'id')
                ->toArray();
        }

        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['collection_id'];
            $slug = (string) ($idToSlug[$cid] ?? '');
            if ($slug === '') {
                continue;
            }

            $limit = (int) ($r['limit'] ?? 8);
            if ($limit <= 0) {
                $limit = 8;
            }

            $out[] = [
                'type' => 'collection',
                'slug' => $slug,
                'limit' => $limit,
                'layout' => 'grid',
                'title_override' => '', // legacy title is not forced on home; keep clean
            ];
        }

        // You can prepend hero/newsletter here if you want defaults; we leave as-is.
        return $out;
    }
}
