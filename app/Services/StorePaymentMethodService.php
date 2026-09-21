<?php

namespace App\Services;

use App\Models\StoreSetting;

/**
 * Manual (offline) storefront payment methods — GCash, Bank Transfer and
 * Cash on Pickup. One place builds the shopper-facing account details so
 * checkout, the thank-you page, the order page and the emails all agree.
 */
class StorePaymentMethodService
{
    /** Methods whose payment happens off-site and is evidenced by a screenshot. */
    public const PROOF_METHODS = ['gcash', 'bank_transfer'];

    /** Methods that mean "collect the order at a branch". */
    public const PICKUP_METHODS = ['cash_on_pickup'];

    /**
     * Every enabled manual method, ready to render.
     *
     * @return array<string, array>
     */
    public static function enabled(?StoreSetting $s = null): array
    {
        $s = $s ?: StoreSetting::first();
        if (! $s) {
            return [];
        }

        $out = [];

        if ($s->payment_gcash_enabled) {
            $out['gcash'] = [
                'code' => 'gcash',
                'label' => __('messages.GCash'),
                'requires_proof' => true,
                'is_pickup' => false,
                'instructions' => (string) ($s->gcash_instructions ?? ''),
                'qr_url' => $s->gcash_qr_path ? url($s->gcash_qr_path) : null,
                'details' => array_values(array_filter([
                    $s->gcash_account_name ? ['label' => __('messages.AccountName'), 'value' => $s->gcash_account_name] : null,
                    $s->gcash_account_number ? ['label' => __('messages.MobileNumber'), 'value' => $s->gcash_account_number, 'copy' => true] : null,
                ])),
            ];
        }

        if ($s->payment_bank_transfer_enabled) {
            $out['bank_transfer'] = [
                'code' => 'bank_transfer',
                'label' => __('messages.BankTransfer'),
                'requires_proof' => true,
                'is_pickup' => false,
                'instructions' => (string) ($s->bank_instructions ?? ''),
                'qr_url' => null,
                'details' => array_values(array_filter([
                    $s->bank_name ? ['label' => __('messages.BankName'), 'value' => $s->bank_name] : null,
                    $s->bank_account_name ? ['label' => __('messages.AccountName'), 'value' => $s->bank_account_name] : null,
                    $s->bank_account_number ? ['label' => __('messages.AccountNumber'), 'value' => $s->bank_account_number, 'copy' => true] : null,
                    $s->bank_branch ? ['label' => __('messages.Branch'), 'value' => $s->bank_branch] : null,
                ])),
            ];
        }

        if ($s->payment_cash_on_pickup_enabled) {
            $out['cash_on_pickup'] = [
                'code' => 'cash_on_pickup',
                'label' => __('messages.CashOnPickup'),
                'requires_proof' => false,
                'is_pickup' => true,
                'instructions' => (string) ($s->pickup_instructions ?? ''),
                'qr_url' => null,
                'details' => [],
            ];
        }

        return $out;
    }

    /** Details for one method, or null when it isn't enabled. */
    public static function get(?string $code, ?StoreSetting $s = null): ?array
    {
        return $code ? (static::enabled($s)[$code] ?? null) : null;
    }

    public static function requiresProof(?string $code): bool
    {
        return in_array((string) $code, self::PROOF_METHODS, true);
    }

    public static function isPickup(?string $code): bool
    {
        return in_array((string) $code, self::PICKUP_METHODS, true);
    }

    /** Human label for any storefront payment method code. */
    public static function label(?string $code): string
    {
        return match ((string) $code) {
            'gcash' => __('messages.GCash'),
            'bank_transfer' => __('messages.BankTransfer'),
            'cash_on_pickup' => __('messages.CashOnPickup'),
            'cod' => __('messages.CashOnDelivery'),
            'mobile_money' => __('messages.MobileMoney'),
            'credit_card' => __('messages.CreditCard'),
            'wallet' => __('messages.PayWithWallet'),
            'paypal' => 'PayPal',
            'paystack' => 'Paystack',
            'flutterwave' => 'Flutterwave',
            'razorpay' => 'Razorpay',
            'bkash' => 'bKash',
            'sslcommerz' => 'SSLCommerz',
            default => (string) $code,
        };
    }
}
