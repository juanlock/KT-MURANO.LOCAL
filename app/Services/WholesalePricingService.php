<?php

namespace App\Services;

use App\Models\ProductPriceTier;
use App\Models\Setting;

/**
 * Wholesale Pricing by Quantity — resolves the per-piece price a product sells
 * at once the ordered quantity reaches one of its configured quantity breaks.
 *
 * A tier price REPLACES the retail base price (products.price) and is then run
 * through whatever discount/tax/unit pipeline the calling surface already uses,
 * so POS lines, storefront cart lines, checkout totals, invoices and receipts
 * all agree. Below the first tier's min_qty the retail price is used unchanged.
 *
 * Everything here is inert unless BOTH the global
 * `settings.enable_wholesale_pricing` switch is on AND the product has tiers,
 * so turning the feature off leaves standard pricing byte-for-byte unchanged
 * (and never deletes the configured tiers).
 */
class WholesalePricingService
{
    /** Per-request cache of the global toggle. */
    private ?bool $enabled = null;

    /** Per-request cache of product_id => tier list (already sorted). */
    private array $cache = [];

    /** Is the module switched on for the whole system? */
    public function enabled(): bool
    {
        if ($this->enabled === null) {
            $setting = Setting::whereNull('deleted_at')->first();
            $this->enabled = (bool) ($setting->enable_wholesale_pricing ?? false);
        }

        return $this->enabled;
    }

    /**
     * Tier ladders for the given products, keyed by product id. Products with
     * no tiers are simply absent from the map. Returns an empty map when the
     * feature is off, so callers need no extra guard.
     *
     * @param  iterable<int|string>  $productIds
     * @return array<int, array<int, array{min_qty: float, max_qty: float|null, price: float}>>
     */
    public function tiersFor(iterable $productIds): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $ids = [];
        foreach ($productIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        $missing = array_values(array_filter($ids, fn ($id) => ! array_key_exists($id, $this->cache)));
        if ($missing) {
            // Seed every requested id so a product without tiers is not re-queried.
            foreach ($missing as $id) {
                $this->cache[$id] = [];
            }

            $rows = ProductPriceTier::whereIn('product_id', $missing)
                ->whereNull('deleted_at')
                ->orderBy('min_qty')
                ->get(['product_id', 'min_qty', 'max_qty', 'price']);

            foreach ($rows as $row) {
                $this->cache[(int) $row->product_id][] = [
                    'min_qty' => (float) $row->min_qty,
                    'max_qty' => $row->max_qty === null ? null : (float) $row->max_qty,
                    'price' => (float) $row->price,
                ];
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (! empty($this->cache[$id])) {
                $out[$id] = $this->cache[$id];
            }
        }

        return $out;
    }

    /**
     * The tier ladder of a single product ([] when it has none / feature off).
     *
     * @return array<int, array{min_qty: float, max_qty: float|null, price: float}>
     */
    public function tiersForProduct($productId): array
    {
        return $this->tiersFor([$productId])[(int) $productId] ?? [];
    }

    /**
     * The tier that applies to `$qty`, or null when the quantity is below the
     * first break (retail territory). The narrowest match wins: brackets are
     * sorted by min_qty and the last one whose min_qty is reached — and whose
     * max_qty is not exceeded — is used.
     *
     * @param  array<int, array{min_qty: float, max_qty: float|null, price: float}>  $tiers
     * @return array{min_qty: float, max_qty: float|null, price: float}|null
     */
    public static function matchTier(array $tiers, float $qty): ?array
    {
        $match = null;
        foreach ($tiers as $tier) {
            $min = (float) ($tier['min_qty'] ?? 0);
            $max = $tier['max_qty'] ?? null;
            if ($qty + 1e-9 < $min) {
                continue;
            }
            if ($max !== null && $qty > (float) $max + 1e-9) {
                continue;
            }
            if ($match === null || $min >= (float) $match['min_qty']) {
                $match = $tier;
            }
        }

        return $match;
    }

    /**
     * Per-piece price for `$qty` units of `$productId`: the matching tier's
     * price, or `$retailPrice` when no tier applies (feature off, no tiers
     * configured, or quantity below the first break).
     */
    public function unitPrice($productId, float $qty, float $retailPrice): float
    {
        $tiers = $this->tiersForProduct($productId);
        if (! $tiers) {
            return $retailPrice;
        }

        $tier = self::matchTier($tiers, $qty);

        return $tier ? (float) $tier['price'] : $retailPrice;
    }
}
