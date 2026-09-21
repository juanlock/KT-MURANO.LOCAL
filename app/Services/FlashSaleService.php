<?php

namespace App\Services;

use App\Models\FlashSale;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the currently-running flash-sale discount for products.
 *
 * Used by the storefront (to display flash prices) and by CheckoutService
 * (to charge the flash price server-side, so it can't be tampered with).
 */
class FlashSaleService
{
    /** Per-request cache of product_id => ['type','value']. */
    private ?array $rules = null;

    /**
     * Map of product_id => ['type' => percent|fixed, 'value' => float] for all
     * products in a running flash sale. When a product is in more than one
     * running sale, the biggest discount (lowest resulting factor) wins.
     */
    public function runningRules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $runningIds = FlashSale::running()->pluck('id');
        if ($runningIds->isEmpty()) {
            return $this->rules = [];
        }

        $rows = DB::table('flash_sale_product')
            ->whereIn('flash_sale_id', $runningIds)
            ->get(['product_id', 'discount_type', 'discount_value']);

        $map = [];
        foreach ($rows as $r) {
            $existing = $map[$r->product_id] ?? null;
            // Prefer the rule that discounts more (percentage-equivalent).
            $weight = $r->discount_type === 'fixed' ? (float) $r->discount_value : (float) $r->discount_value;
            if (! $existing || $weight > $existing['_w']) {
                $map[$r->product_id] = [
                    'type' => $r->discount_type,
                    'value' => (float) $r->discount_value,
                    '_w' => $weight,
                ];
            }
        }

        return $this->rules = $map;
    }

    public function hasRunningSales(): bool
    {
        return ! empty($this->runningRules());
    }

    /** The flash rule for a product, or null when it is not on sale. */
    public function ruleFor($productId): ?array
    {
        $rules = $this->runningRules();

        return $rules[$productId] ?? null;
    }

    /**
     * Apply the flash discount to a base (pre-tax) price when the product is on
     * sale; otherwise return the price unchanged.
     */
    public function discountedBase($productId, float $basePrice): float
    {
        $rule = $this->ruleFor($productId);
        if (! $rule) {
            return round($basePrice, 2);
        }

        return FlashSale::applyDiscount($basePrice, $rule['type'], $rule['value']);
    }
}
