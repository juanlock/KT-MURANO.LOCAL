<?php

namespace App\Services;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\StoreCoupon;
use App\Models\StoreCouponRedemption;
use App\Models\StoreSetting;
use App\Models\TaxRate;
use App\Services\ShippingZoneService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for online-store checkout maths.
 *
 * Every entry point that touches money (the quote endpoint, the Stripe
 * PaymentIntent, and the final order placement) runs the numbers through
 * here so the customer can never influence prices, tax, or shipping from
 * the browser.
 */
class CheckoutService
{
    public function __construct(
        private FlashSaleService $flashSales,
        private WholesalePricingService $wholesale
    ) {
    }

    /** Thrown for any checkout validation problem; carries an HTTP status + payload. */
    public function fail(string $message, int $status = 422, array $extra = []): CheckoutException
    {
        return new CheckoutException($message, $status, $extra);
    }

    /**
     * Warehouses the online store sells from (admin multi-select on the store
     * settings; empty selection = all warehouses). Stock is displayed and
     * validated as the SUM across these warehouses.
     *
     * @return int[]
     */
    public function activeWarehouseIds(): array
    {
        $ids = StoreSetting::storeWarehouseIds();

        if (! $ids) {
            throw $this->fail(__('messages.NoWarehouseConfigured'));
        }

        return $ids;
    }

    /**
     * Warehouse the confirmed order is booked under: the requested one when it
     * belongs to the active set, otherwise the active warehouse holding the
     * most stock for the cart's products.
     */
    public function selectFulfilmentWarehouseId(array $items, $requested = null): int
    {
        $warehouseIds = $this->activeWarehouseIds();

        $requested = (int) ($requested ?? 0);
        if ($requested && in_array($requested, $warehouseIds, true)) {
            return $requested;
        }

        $productIds = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values();

        $best = product_warehouse::whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->selectRaw('warehouse_id, SUM(qte) as total_qty')
            ->groupBy('warehouse_id')
            ->orderByDesc('total_qty')
            ->value('warehouse_id');

        return (int) ($best ?: $warehouseIds[0]);
    }

    /**
     * Build normalized line items using SERVER-side prices, and the subtotal.
     * Also flags pre-order lines and enforces pre-order limits.
     *
     * Stock is the combined quantity across the given warehouses.
     *
     * @param  int|int[]  $warehouseIds
     * @return array{items: array<int,array>, subtotal: float, has_preorder_items: bool}
     */
    public function buildLineItems(array $items, int|array $warehouseIds): array
    {
        $warehouseIds = array_values(array_map('intval', (array) $warehouseIds));
        $ids = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values();

        $products = Product::whereIn('id', $ids)
            ->get(['id', 'name', 'price', 'TaxNet', 'discount', 'discount_method', 'tax_method',
                   'is_preorder', 'preorder_always', 'preorder_available_date', 'preorder_limit', 'preorder_note'])
            ->keyBy('id');

        if ($products->count() !== $ids->count()) {
            $missing = $ids->diff($products->keys())->values();
            throw $this->fail(__('messages.SomeProductsNotFound'), 422, ['product_ids' => $missing]);
        }

        $variantIds = collect($items)->pluck('product_variant_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::whereIn('id', $variantIds)->get(['id', 'product_id', 'price'])->keyBy('id');

        $stockByKey = [];
        product_warehouse::whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $ids)
            ->whereNull('deleted_at')
            ->get(['product_id', 'product_variant_id', 'qte'])
            ->each(function ($r) use (&$stockByKey) {
                $key = $r->product_id.':'.($r->product_variant_id ?? 'p');
                $stockByKey[$key] = ($stockByKey[$key] ?? 0.0) + (float) $r->qte;
            });

        $preorderQtyAccumulator = [];
        $normalized = [];
        $subtotal = 0.0;
        $hasPreorderItems = false;

        foreach ($items as $i) {
            $pid = (int) $i['product_id'];
            $pvid = ! empty($i['product_variant_id']) ? (int) $i['product_variant_id'] : null;
            $qty = max(1, (float) $i['qty']);

            $product = $products->get($pid);
            $price = (float) $product->price;
            if ($pvid) {
                $variant = $variants->get($pvid);
                if ($variant && (int) $variant->product_id === $pid) {
                    $price = (float) $variant->price;
                }
            }
            $price = round(max(0, $price), 2);

            // Wholesale Pricing by Quantity: once the line quantity reaches a
            // configured break, the tier price REPLACES the retail base price.
            // No-op when the feature is off, the product has no ladder, or the
            // quantity is still below the first break.
            $wholesalePrice = round($this->wholesale->unitPrice($pid, $qty, $price), 2);

            // Apply the running flash-sale price server-side so the customer is
            // charged the discounted price and can never tamper with it.
            $price = $this->flashSales->discountedBase($pid, $price);

            // A product can be both on flash sale and past a wholesale break —
            // the shopper gets whichever is cheaper, never both stacked.
            $price = min($price, $wholesalePrice);

            $line = round($qty * $price, 2);

            $stockKey = $pvid ? "{$pid}:{$pvid}" : "{$pid}:p";
            $currentStock = $stockByKey[$stockKey] ?? 0.0;
            $isPreorder = false;

            if ($product->is_preorder && ($product->preorder_always || $currentStock <= 0)) {
                $isPreorder = true;
                $hasPreorderItems = true;

                if ($product->preorder_limit !== null) {
                    $alreadyQueued = $preorderQtyAccumulator[$pid] ?? 0;
                    if (($alreadyQueued + $qty) > $product->preorder_limit) {
                        throw $this->fail(__('messages.PreorderLimitExceededFor', [
                            'name' => $product->name,
                            'max' => $product->preorder_limit,
                        ]));
                    }
                    $preorderQtyAccumulator[$pid] = $alreadyQueued + $qty;
                }
            }

            $normalized[] = [
                'product_id' => $pid,
                'product_variant_id' => $pvid,
                'weight' => (float) ($product->weight ?? 0),
                'product_name' => $product->name,
                'qty' => $qty,
                'price' => $price,
                'line_total' => $line,
                'TaxNet' => (float) ($product->TaxNet ?? 0),
                'discount' => (float) ($product->discount ?? 0),
                'discount_method' => (string) ($product->discount_method ?? '1'),
                'tax_method' => (string) ($product->tax_method ?? '1'),
                'is_preorder' => $isPreorder,
                'current_stock' => $currentStock,
            ];

            $subtotal += $line;
        }

        return [
            'items' => $normalized,
            'subtotal' => round(max(0, $subtotal), 2),
            'has_preorder_items' => $hasPreorderItems,
        ];
    }

    /**
     * Active shipping methods available for the given country.
     *
     * @return Collection<int,ShippingMethod>
     */
    public function availableShippingMethods(
        ?string $country,
        array $productIds = [],
        ?string $state = null,
        float $subtotal = 0.0,
        float $weight = 0.0
    ): Collection {
        // Zones supersede the flat method list, but ONLY once the store has
        // configured at least one — otherwise every existing install keeps the
        // exact behaviour it has today.
        if (ShippingZoneService::inUse()) {
            $methods = ShippingZoneService::ratesFor($country, $state, $subtotal, $weight);
        } else {
            $methods = ShippingMethod::with('regions')
                ->whereNull('shipping_zone_id')
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get()
                ->filter(fn (ShippingMethod $m) => $m->availableForCountry($country));
        }

        return $this->restrictToProducts($methods, $productIds)->values();
    }

    /** Total cart weight, in whatever unit products.weight is kept in. */
    public function cartWeight(array $items): float
    {
        return round(collect($items)->sum(
            fn ($i) => (float) ($i['weight'] ?? 0) * (float) ($i['qty'] ?? 0)
        ), 3);
    }

    /**
     * Narrow a method list to what EVERY product in the cart allows.
     *
     * A product with no rows in product_shipping_method is unrestricted and
     * constrains nothing — so carts of ordinary products behave exactly as
     * before, and one restricted item narrows the whole order.
     */
    private function restrictToProducts(Collection $methods, array $productIds): Collection
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        if (! $productIds || $methods->isEmpty()) {
            return $methods;
        }

        $rows = DB::table('product_shipping_method')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'shipping_method_id'])
            ->groupBy('product_id');

        foreach ($rows as $allowedForProduct) {
            $allowed = $allowedForProduct->pluck('shipping_method_id')
                ->map(fn ($id) => (int) $id)->all();
            $methods = $methods->filter(fn (ShippingMethod $m) => in_array((int) $m->id, $allowed, true));
        }

        return $methods;
    }

    /**
     * Resolve the chosen shipping method + cost.
     * When no shipping methods are configured at all, shipping is free/optional.
     *
     * @return array{method: ?ShippingMethod, cost: float}
     */
    public function resolveShipping(
        $shippingMethodId,
        ?string $country,
        array $productIds = [],
        ?string $state = null,
        float $subtotal = 0.0,
        float $weight = 0.0
    ): array {
        $available = $this->availableShippingMethods($country, $productIds, $state, $subtotal, $weight);

        // Nothing configured for this store → no shipping charge, no selection needed.
        if (! ShippingZoneService::inUse() && ShippingMethod::where('active', true)->count() === 0) {
            return ['method' => null, 'cost' => 0.0];
        }

        if ($available->isEmpty()) {
            throw $this->fail(__('messages.NoShippingForRegion'));
        }

        if (! $shippingMethodId) {
            throw $this->fail(__('messages.ShippingMethodRequired'));
        }

        $method = $available->firstWhere('id', (int) $shippingMethodId);
        if (! $method) {
            throw $this->fail(__('messages.ShippingMethodUnavailable'));
        }

        return ['method' => $method, 'cost' => round((float) $method->price, 2)];
    }

    /**
     * Location-based tax for a taxable base amount.
     *
     * @return array{rate: float, amount: float, tax_rate_id: ?int}
     */
    public function resolveTax(float $taxableBase, ?string $country, ?string $state = null): array
    {
        $rate = TaxRate::resolveForLocation($country, $state);
        if (! $rate) {
            return ['rate' => 0.0, 'amount' => 0.0, 'tax_rate_id' => null];
        }

        $pct = (float) $rate->rate;
        $amount = round(max(0, $taxableBase) * $pct / 100, 2);

        return ['rate' => $pct, 'amount' => $amount, 'tax_rate_id' => $rate->id];
    }

    /**
     * Validate a coupon code against a subtotal + customer, returning the coupon
     * and the discount it yields. Throws CheckoutException when the code is
     * present but not usable. Returns nulls when no code is given.
     *
     * @return array{coupon: ?StoreCoupon, discount: float}
     */
    public function resolveCoupon(?string $code, float $subtotal, $clientId = null): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return ['coupon' => null, 'discount' => 0.0];
        }

        $coupon = StoreCoupon::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first();

        if (! $coupon || ! $coupon->enabled) {
            throw $this->fail(__('messages.CouponInvalid'));
        }

        $now = now();
        if (($coupon->starts_at && $coupon->starts_at->gt($now)) ||
            ($coupon->ends_at && $coupon->ends_at->lt($now))) {
            throw $this->fail(__('messages.CouponExpired'));
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw $this->fail(__('messages.CouponMinOrder', [
                'amount' => number_format((float) $coupon->min_order_amount, 2),
            ]));
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            throw $this->fail(__('messages.CouponUsedUp'));
        }

        if ($coupon->per_customer_limit !== null && $clientId) {
            $mine = StoreCouponRedemption::where('coupon_id', $coupon->id)
                ->where('client_id', $clientId)->count();
            if ($mine >= $coupon->per_customer_limit) {
                throw $this->fail(__('messages.CouponPerCustomerLimit'));
            }
        }

        $discount = $coupon->discountFor($subtotal);
        if ($discount <= 0) {
            throw $this->fail(__('messages.CouponInvalid'));
        }

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    /**
     * Full server-side breakdown for a cart + customer location + shipping choice
     * + optional coupon. Discount applies to the subtotal; tax is charged on the
     * discounted amount.
     *
     * @return array full breakdown
     */
    public function calculate(array $items, int|array $warehouseIds, ?string $country, ?string $state, $shippingMethodId, ?string $couponCode = null, $clientId = null, bool $isPickup = false): array
    {
        $lines = $this->buildLineItems($items, $warehouseIds);
        $subtotal = $lines['subtotal'];

        $couponResult = $this->resolveCoupon($couponCode, $subtotal, $clientId);
        $discount = $couponResult['discount'];
        $netSubtotal = round(max(0, $subtotal - $discount), 2);

        // Collected at a branch: nothing ships, so no method is required and
        // no shipping is charged.
        $shipping = $isPickup
            ? ['method' => null, 'cost' => 0.0]
            : $this->resolveShipping(
                $shippingMethodId,
                $country,
                collect($lines['items'])->pluck('product_id')->filter()->unique()->all(),
                $state,
                $netSubtotal,
                $this->cartWeight($lines['items'])
            );
        $tax = $this->resolveTax($netSubtotal, $country, $state);

        $total = round($netSubtotal + $tax['amount'] + $shipping['cost'], 2);

        return [
            'items' => $lines['items'],
            'has_preorder_items' => $lines['has_preorder_items'],
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon' => $couponResult['coupon'],
            'tax' => $tax['amount'],
            'tax_rate' => $tax['rate'],
            'shipping_cost' => $shipping['cost'],
            'shipping_method' => $shipping['method'],
            'total' => $total,
        ];
    }
}
