<?php

namespace App\Services;

use App\Models\OnlineOrder;
use App\Models\Setting;
use App\Models\StoreSetting;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Builds and validates invoices for online-store orders.
 *
 * The invoice is generated from the values FROZEN on the order at checkout
 * (line totals, tax, shipping, customer snapshot), and validated for internal
 * consistency before it is rendered so a wrong invoice is never produced.
 */
class OnlineOrderInvoiceService
{
    /**
     * Assemble the invoice data + validate it.
     *
     * @return array{order: OnlineOrder, settings: ?Setting, store: ?StoreSetting, symbol: string, lines: array, subtotal: float, tax: float, tax_rate: float, shipping: float, total: float, valid: bool, problems: array}
     */
    public function build(OnlineOrder $order): array
    {
        $order->loadMissing(['items.product', 'items.productVariant', 'client', 'shippingMethod']);

        $settings = Setting::whereNull('deleted_at')->first();
        $store = StoreSetting::first();
        // Multi-Currency: print in the currency the order was placed in
        // (base symbol with rate 1 for legacy orders).
        $docCurrency = StoreCurrencyService::forDocument($order);
        $symbol = $docCurrency['symbol'] ?: ($store->currency_code ?? '');

        $lines = [];
        $computedSubtotal = 0.0;

        foreach ($order->items as $it) {
            $name = optional($it->product)->name ?? ('#'.$it->product_id);
            $variant = optional($it->productVariant)->name;
            $qty = (float) $it->qty;
            $price = (float) $it->price;
            $lineTotal = $it->line_total !== null ? (float) $it->line_total : round($qty * $price, 2);

            $lines[] = [
                'name' => $variant ? ($name.' - '.$variant) : $name,
                'qty' => $qty,
                'price' => $price,
                'tax' => (float) ($it->TaxNet ?? 0),
                'line_total' => $lineTotal,
                'is_preorder' => (bool) $it->is_preorder,
            ];

            $computedSubtotal += $lineTotal;
        }
        $computedSubtotal = round($computedSubtotal, 2);

        // Use frozen values when present; fall back to computed for legacy orders.
        $subtotal = (float) $order->subtotal > 0 ? (float) $order->subtotal : $computedSubtotal;
        $tax = (float) $order->tax;
        $shipping = (float) $order->shipping_cost;
        $total = (float) $order->total;

        $customer = [
            'name' => $order->customer_name ?: optional($order->client)->name,
            'email' => $order->customer_email ?: optional($order->client)->email,
            'phone' => $order->customer_phone ?: optional($order->client)->phone,
            'address' => $order->shipping_address ?: optional($order->client)->adresse,
            'city' => $order->shipping_city,
            'state' => $order->shipping_state,
            'zip' => $order->shipping_zip,
            'country' => $order->shipping_country ?: optional($order->client)->country,
        ];

        $problems = $this->validateConsistency($subtotal, $tax, $shipping, $total, $computedSubtotal, $customer);

        // Multi-Currency: consistency was validated on the frozen BASE values;
        // only the rendered amounts convert (per-line tax stays a percent).
        $rate = (float) ($docCurrency['rate'] ?? 1) ?: 1.0;
        if ($rate !== 1.0) {
            foreach ($lines as &$line) {
                $line['price'] = $line['price'] * $rate;
                $line['line_total'] = $line['line_total'] * $rate;
            }
            unset($line);
            $subtotal *= $rate;
            $tax *= $rate;
            $shipping *= $rate;
            $total *= $rate;
        }

        return [
            'order' => $order,
            'settings' => $settings,
            'store' => $store,
            'symbol' => $symbol,
            'customer' => $customer,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate' => (float) $order->tax_rate,
            'shipping' => $shipping,
            'total' => $total,
            'valid' => empty($problems),
            'problems' => $problems,
        ];
    }

    /**
     * Consistency checks: line sum matches subtotal, breakdown matches total,
     * and required customer fields exist. Returns a list of problem keys.
     */
    private function validateConsistency(float $subtotal, float $tax, float $shipping, float $total, float $computedSubtotal, array $customer): array
    {
        $problems = [];
        $tolerance = 0.02;

        if (abs($subtotal - $computedSubtotal) > $tolerance) {
            $problems[] = 'subtotal_mismatch';
        }

        if (abs(($subtotal + $tax + $shipping) - $total) > $tolerance) {
            $problems[] = 'total_mismatch';
        }

        if ($tax < 0 || $shipping < 0 || $total < 0) {
            $problems[] = 'negative_amount';
        }

        foreach (['name', 'email', 'address', 'country'] as $field) {
            if (empty(trim((string) ($customer[$field] ?? '')))) {
                $problems[] = 'missing_'.$field;
            }
        }

        if (! empty($customer['email']) && ! filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'invalid_email';
        }

        return $problems;
    }

    /** Render the invoice HTML (used both for PDF and email body). */
    public function renderHtml(OnlineOrder $order): string
    {
        return view('pdf.online_order_invoice', $this->build($order))->render();
    }

    /**
     * Generate the invoice PDF. Throws when the invoice fails validation so a
     * wrong invoice is never emitted.
     */
    public function pdf(OnlineOrder $order)
    {
        $data = $this->build($order);

        if (! $data['valid']) {
            throw new CheckoutException(
                __('messages.InvoiceValidationFailed'),
                422,
                ['problems' => $data['problems']]
            );
        }

        $html = view('pdf.online_order_invoice', $data)->render();

        return Pdf::loadHTML($html, 'UTF-8');
    }
}
