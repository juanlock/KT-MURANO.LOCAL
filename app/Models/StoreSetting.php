<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    public const TEMPLATE_COMPANY_ID = 999;

    /**
     * The store settings of the store being used now (2026-10-27: the storefront,
     * cart, checkout and payments read the FIRST row = one company's store for
     * everybody). The company comes from, in order: the request (the company's
     * website path, its domain or ?company=, remembered in the session so the
     * cart / checkout calls that follow keep it), the session, the signed-in
     * store customer, a staff user's acting company; else the first row as before.
     */
    public static function current(): self
    {
        $cid = self::currentCompanyId();

        return $cid ? static::forCompany($cid) : (static::query()->orderBy('id')->first() ?? new static);
    }

    public static function currentCompanyId(): ?int
    {
        $r = app()->bound('request') && ! app()->runningInConsole() ? request() : null;
        // Storefront requests (shop pages, cart, checkout, store account): the shop's company.
        // Anything else (the admin panel): the staff user's acting company.
        $isStore = $r && function_exists('is_store_request') && is_store_request($r);
        if ($r && ($cid = \App\Services\FrontPage::explicitCompanyId($r))) {
            if ($isStore && $r->hasSession()) {
                $r->session()->put('store_company', $cid);
            }

            return $cid;
        }
        try {
            if ($isStore) {
                if ($r->hasSession() && ($cid = (int) $r->session()->get('store_company'))) {
                    return $cid;
                }
                if (($account = auth('store')->user()) && ($cid = (int) optional($account->client)->company_id)) {
                    return $cid;
                }
            }
            $staff = auth('api')->user() ?? auth('web')->user();
            if ($staff && ! in_array($staff->type, ['customer'], true)) {
                return \App\Support\CompanyFiles::actingCompanyId($r);
            }
        } catch (\Throwable $e) {
            // no auth in this context (console, early boot)
        }

        return null;
    }

    /** The store settings of the company a warehouse belongs to (orders, returns, their emails). */
    public static function forWarehouse(?int $warehouseId): self
    {
        $cid = $warehouseId ? (int) \Illuminate\Support\Facades\DB::table('warehouses')->where('id', $warehouseId)->value('company_id') : 0;

        return $cid ? static::forCompany($cid) : static::current();
    }

    public static function forCompany(?int $companyId): self
    {
        $companyId = $companyId ?: self::TEMPLATE_COMPANY_ID;
        if ($existing = static::where('company_id', $companyId)->first()) {
            return $existing;
        }
        $template = static::where('company_id', self::TEMPLATE_COMPANY_ID)->first()
            ?? static::first();
        $attrs = $template
            ? collect($template->getAttributes())->except(['id', 'company_id', 'created_at', 'updated_at'])->all()
            : [];
        // forceFill: company_id isn't mass-assignable (and the cloned template
        // attributes may include non-fillable columns) -- create() silently
        // saved a company-less row, so every later call cloned another one.
        return tap((new static)->forceFill($attrs + ['company_id' => $companyId]))->save();
    }

    protected $fillable = [
        'enabled', 'registration_enabled', 'require_invite_code', 'require_admin_approval', 'require_email_verification',
        'allow_cancellations', 'return_window_days', 'auto_approve_reviews', 'cookie_consent_enabled',
        'wallet_enabled', 'wallet_allow_negative', 'wallet_refund_destination', 'wallet_withdrawal_enabled', 'wallet_min_withdrawal',
        'payment_cod_enabled', 'payment_mobile_money_enabled', 'payment_stripe_enabled',
        'payment_gcash_enabled', 'gcash_account_name', 'gcash_account_number', 'gcash_qr_path', 'gcash_instructions',
        'payment_bank_transfer_enabled', 'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_instructions',
        'payment_cash_on_pickup_enabled', 'pickup_instructions',
        'paypal_enabled', 'paypal_client_id', 'paypal_client_secret', 'paypal_test_mode', 'paypal_webhook_id',
        'paystack_enabled', 'paystack_public_key', 'paystack_secret_key',
        'flutterwave_enabled', 'flutterwave_public_key', 'flutterwave_secret_key', 'flutterwave_secret_hash',
        'razorpay_enabled', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret',
        'bkash_enabled', 'bkash_app_key', 'bkash_app_secret', 'bkash_username', 'bkash_password', 'bkash_sandbox',
        'sslcommerz_enabled', 'sslcommerz_store_id', 'sslcommerz_store_password', 'sslcommerz_sandbox',
        'store_name', 'theme', 'theme_options', 'logo_path', 'favicon_path',
        'primary_color', 'secondary_color', 'font_family',
        'hero_title', 'hero_subtitle', 'hero_image_path',
        'homepage_lineup', 'homepage_layout', 'social_links', 'menus',
        'default_warehouse_id', 'warehouse_ids', 'allow_overselling', 'hide_out_of_stock', 'hide_prices_for_guests', 'show_stock', 'currency_code', 'display_currency_id', 'language',
        'contact_email', 'contact_phone', 'contact_address',
        'seo_meta_title', 'seo_meta_description', 'store_domain', 'seo_title_template',
        'store_url_path', 'store_use_root_domain',
        'topbar_text_left', 'topbar_text_right', 'footer_text',
        'text_translations',
    ];

    /**
     * Admin-authored storefront copy that can be translated per locale. These
     * all live in one `text_translations` map: {field: {locale: text}}.
     * `store_name` is deliberately absent — a brand name is not translated.
     */
    public const TRANSLATABLE_TEXT = [
        'hero_title', 'hero_subtitle',
        'topbar_text_left', 'topbar_text_right', 'footer_text',
        'seo_meta_title', 'seo_meta_description',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'registration_enabled' => 'boolean',
        'require_invite_code' => 'boolean',
        'require_admin_approval' => 'boolean',
        'require_email_verification' => 'boolean',
        'allow_cancellations' => 'boolean',
        'return_window_days' => 'integer',
        'auto_approve_reviews' => 'boolean',
        'cookie_consent_enabled' => 'boolean',
        'wallet_enabled' => 'boolean',
        'wallet_allow_negative' => 'boolean',
        'wallet_withdrawal_enabled' => 'boolean',
        'wallet_min_withdrawal' => 'decimal:2',
        'payment_cod_enabled' => 'boolean',
        'payment_mobile_money_enabled' => 'boolean',
        'payment_stripe_enabled' => 'boolean',
        'payment_gcash_enabled' => 'boolean',
        'payment_bank_transfer_enabled' => 'boolean',
        'payment_cash_on_pickup_enabled' => 'boolean',
        'paypal_enabled' => 'boolean',
        'paypal_test_mode' => 'boolean',
        'paystack_enabled' => 'boolean',
        'flutterwave_enabled' => 'boolean',
        'razorpay_enabled' => 'boolean',
        'bkash_enabled' => 'boolean',
        'bkash_sandbox' => 'boolean',
        'sslcommerz_enabled' => 'boolean',
        'sslcommerz_sandbox' => 'boolean',
        'store_use_root_domain' => 'boolean',
        'allow_overselling' => 'boolean',
        'hide_out_of_stock' => 'boolean',
        'hide_prices_for_guests' => 'boolean',
        'show_stock' => 'boolean',
        'display_currency_id' => 'integer',
        'text_translations' => 'array',
        'homepage_lineup' => 'array',
        'theme_options' => 'array',
        'social_links' => 'array',
        'menus' => 'array',
        'warehouse_ids' => 'array',
    ];

    /** Never serialized into API/storefront responses. */
    protected $hidden = [
        'paypal_client_secret', 'paystack_secret_key',
        'flutterwave_secret_key', 'flutterwave_secret_hash',
        'razorpay_key_secret', 'razorpay_webhook_secret',
        'bkash_app_secret', 'bkash_password',
        'sslcommerz_store_password',
    ];

    /**
     * Warehouses the online store sells from (stock display + checkout).
     * warehouse_ids = NULL/empty means "all warehouses"; otherwise the stored
     * list filtered to warehouses that still exist. Falls back to the legacy
     * default_warehouse_id, then to every warehouse.
     *
     * @return int[]
     */
    public function activeWarehouseIds(): array
    {
        $all = Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($this->warehouse_ids === null) {
            return $all;
        }

        $selected = array_values(array_filter(array_map('intval', (array) $this->warehouse_ids)));
        $valid = array_values(array_intersect($selected, $all));
        if ($valid) {
            return $valid;
        }

        if ($this->default_warehouse_id && in_array((int) $this->default_warehouse_id, $all, true)) {
            return [(int) $this->default_warehouse_id];
        }

        return $all;
    }

    /**
     * Static convenience for callers without a loaded settings row.
     *
     * @return int[]
     */
    public static function storeWarehouseIds(): array
    {
        $s = static::current();

        return $s->exists ? $s->activeWarehouseIds() : Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Whether card payments are offered at the storefront checkout. The flag
     * is independent of the stored Stripe keys, and defaults to on so an
     * install that predates it keeps accepting cards.
     */
    public static function stripeEnabled(): bool
    {
        $value = static::current()->payment_stripe_enabled;

        return $value === null ? true : (bool) $value;
    }

    /**
     * Whether a custom storefront domain is configured.
     */
    public function hasCustomDomain(): bool
    {
        return trim((string) ($this->store_domain ?? '')) !== '';
    }

    /**
     * Canonical base URL for the storefront (custom domain if set, else the app URL).
     */
    public function canonicalBase(): string
    {
        $domain = trim((string) ($this->store_domain ?? ''));
        if ($domain !== '') {
            if (! preg_match('#^https?://#i', $domain)) {
                $domain = 'https://'.$domain;
            }

            return rtrim($domain, '/');
        }

        return rtrim(url('/'), '/');
    }

    /**
     * Build the public/canonical URL for a storefront path.
     * When a custom domain is set, the configured store base path (default
     * "online_store/") is dropped so the canonical reflects the clean root
     * domain (mapped at the web-server level).
     */
    public function storeUrl(string $path = ''): string
    {
        $base = $this->canonicalBase();
        $path = ltrim($path, '/');

        $storeBase = store_path();
        if ($this->hasCustomDomain() && $storeBase !== '') {
            $path = preg_replace('#^'.preg_quote($storeBase, '#').'/?#', '', $path);
        }

        return $path === '' ? $base.'/' : $base.'/'.$path;
    }

    /**
     * Canonical URL for a full in-app URL (e.g. from route()).
     */
    public function canonicalForAppUrl(string $appUrl): string
    {
        $path = ltrim((string) (parse_url($appUrl, PHP_URL_PATH) ?? ''), '/');

        return $this->storeUrl($path);
    }

    /**
     * One of the TRANSLATABLE_TEXT fields in the visitor's language, falling
     * back to the plain column when that locale was left blank.
     */
    public function localizedText(string $field, ?string $locale = null): ?string
    {
        $map = $this->text_translations;
        if (is_string($map)) {
            $map = json_decode($map, true);
        }

        $locale = $locale ?: app()->getLocale();
        $value = trim((string) (is_array($map) ? ($map[$field][$locale] ?? '') : ''));

        return $value !== '' ? $value : $this->{$field};
    }

    /**
     * Clean a submitted {field: {locale: text}} map: keep only known fields and
     * storefront locales, drop blanks, and return null when nothing is left.
     */
    public static function cleanTextTranslations($input): ?array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return null;
        }

        $locales = store_locales();
        $clean = [];
        foreach (self::TRANSLATABLE_TEXT as $field) {
            $perLocale = [];
            foreach ((array) ($input[$field] ?? []) as $locale => $text) {
                if (isset($locales[$locale]) && trim((string) $text) !== '') {
                    $perLocale[$locale] = trim((string) $text);
                }
            }
            if ($perLocale) {
                $clean[$field] = $perLocale;
            }
        }

        return $clean ?: null;
    }

    /**
     * A menu item's label in the visitor's language. Labels live inside the
     * `menus` json as an optional `label_translations` map on each item.
     */
    public static function menuItemLabel(array $item, ?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();
        $translations = $item['label_translations'] ?? [];
        if (is_string($translations)) {
            $translations = json_decode($translations, true);
        }
        $value = trim((string) (is_array($translations) ? ($translations[$locale] ?? '') : ''));

        return $value !== '' ? $value : (string) ($item['label'] ?? '');
    }

    /**
     * Resolve a menu item ({type, value}) to a storefront URL.
     * Types: home, shop, flash_sales, contact, wishlist, compare, page, collection, url.
     */
    public static function menuItemUrl(array $item): string
    {
        $type = (string) ($item['type'] ?? 'url');
        $value = trim((string) ($item['value'] ?? ''));

        try {
            switch ($type) {
                case 'home': return route('store.index');
                case 'shop': return route('store.shop');
                case 'flash_sales': return route('store.flash_sales');
                case 'contact': return route('store.contact');
                case 'wishlist': return route('store.wishlist');
                case 'compare': return route('store.compare');
                case 'page': return $value !== '' ? route('store.page', $value) : '#';
                case 'collection': return $value !== '' ? route('store.shop', ['collection' => $value]) : route('store.shop');
                case 'url':
                default:
                    return $value !== '' ? $value : '#';
            }
        } catch (\Throwable $e) {
            return $value !== '' ? $value : '#';
        }
    }
}
