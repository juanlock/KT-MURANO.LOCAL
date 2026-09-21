<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Setting;
use App\utils\helpers;

/**
 * Multi-Currency for the online storefront.
 *
 * The shopper's choice lives in session('store_currency_id'); prices are
 * stored and computed in the BASE currency everywhere (CheckoutService,
 * cart line data-* attributes, order totals) and only converted for display
 * using rate = units of the active currency per 1 base unit.
 *
 * With the module off — or no/invalid selection — active() returns the base
 * currency with rate 1, so every caller degrades to legacy behavior.
 */
class StoreCurrencyService
{
    /** Per-request cache. */
    private static ?array $active = null;

    /** The currency the storefront should display right now. */
    public static function active(): array
    {
        if (self::$active !== null) {
            return self::$active;
        }

        $base = self::base();

        if (! helpers::multi_currency_enabled()) {
            return self::$active = $base;
        }

        $selectedId = self::preferredId();
        if (! $selectedId || (int) $selectedId === (int) ($base['id'] ?? 0)) {
            return self::$active = $base;
        }

        $currency = Currency::whereNull('deleted_at')->find($selectedId);
        if (! $currency) {
            return self::$active = $base;
        }

        return self::$active = [
            'id' => $currency->id,
            'symbol' => (string) $currency->symbol,
            'code' => strtoupper((string) $currency->code),
            'rate' => (float) ($currency->exchange_rate ?? 1) ?: 1.0,
            'is_base' => false,
        ];
    }

    /**
     * The currency id the storefront should display in, highest priority
     * first — mirrors SetStoreLocale's chain for language:
     *
     *   1. session — the header switcher, an override for this session
     *   2. the signed-in customer's saved preference
     *   3. store_settings()->display_currency_id — the admin's default
     *
     * Null falls through to the base currency.
     */
    private static function preferredId()
    {
        $sessionId = session('store_currency_id');
        if ($sessionId) {
            return $sessionId;
        }

        $customerId = optional(\Illuminate\Support\Facades\Auth::guard('store')->user())->preferred_currency_id;
        if ($customerId) {
            return $customerId;
        }

        try {
            return store_settings()->display_currency_id ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The store's base currency (settings.currency_id), rate 1. */
    public static function base(): array
    {
        $baseSymbol = (string) (store_settings()->currency_code ?: '$');
        $baseCurrency = null;
        try {
            $baseId = Setting::where('deleted_at', '=', null)->value('currency_id');
            $baseCurrency = $baseId ? Currency::whereNull('deleted_at')->find($baseId) : null;
        } catch (\Throwable $e) {
            // settings table unavailable (install/migration) — symbol fallback
        }

        return [
            'id' => $baseCurrency->id ?? null,
            'symbol' => (string) ($baseCurrency->symbol ?? $baseSymbol),
            'code' => strtoupper((string) ($baseCurrency->code ?? 'USD')),
            'rate' => 1.0,
            'is_base' => true,
        ];
    }

    /**
     * The currency a stored document (online order / sale) was placed in —
     * order history and thank-you pages must use the order's snapshotted
     * rate, never the current session selection.
     */
    public static function forDocument($doc): array
    {
        $base = self::base();

        if (! $doc || ! helpers::multi_currency_enabled() || empty($doc->currency_id)) {
            return $base;
        }

        $currency = Currency::whereNull('deleted_at')->find($doc->currency_id);
        if (! $currency) {
            return $base;
        }

        $rate = (float) ($doc->exchange_rate ?? 0);
        if ($rate <= 0) {
            $rate = (float) ($currency->exchange_rate ?? 1) ?: 1.0;
        }

        return [
            'id' => $currency->id,
            'symbol' => (string) $currency->symbol,
            'code' => strtoupper((string) $currency->code),
            'rate' => $rate,
            'is_base' => false,
        ];
    }

    /** Currencies offered by the header switcher (empty = hide switcher). */
    public static function options()
    {
        if (! helpers::multi_currency_enabled()) {
            return collect();
        }

        return Currency::whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'name', 'code', 'symbol', 'exchange_rate']);
    }

    /** Store the shopper's selection (validated); returns whether it stuck. */
    public static function select($currencyId): bool
    {
        if (! helpers::multi_currency_enabled()) {
            return false;
        }

        $currency = Currency::whereNull('deleted_at')->find($currencyId);
        if (! $currency) {
            return false;
        }

        session(['store_currency_id' => $currency->id]);
        self::$active = null;

        return true;
    }

    /** Drop the per-request cache after the selection changed underneath us. */
    public static function flush(): void
    {
        self::$active = null;
    }
}
