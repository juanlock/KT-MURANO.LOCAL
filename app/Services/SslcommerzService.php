<?php

namespace App\Services;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SSLCommerz (hosted checkout, gwprocess v4) for the online-store checkout.
 *
 * Same redirect-then-verify pattern as the other store gateways: create a
 * session with a server-generated tran_id and the SERVER-computed total,
 * redirect the customer to the hosted GatewayPageURL, then VALIDATE
 * server-side (validationserverAPI by val_id) on the callback/IPN — the
 * browser POST alone is never trusted. Success/fail/cancel arrive on three
 * separate URLs, POSTed by the gateway. Credentials and the sandbox/live
 * switch live on the store_settings row.
 */
class SslcommerzService
{
    private ?StoreSetting $settings = null;

    private function settings(): ?StoreSetting
    {
        return $this->settings ??= StoreSetting::query()->first();
    }

    /** Enabled by the admin AND both credentials present. */
    public function isConfigured(): bool
    {
        $s = $this->settings();

        return $s
            && (bool) $s->sslcommerz_enabled
            && trim((string) $s->sslcommerz_store_id) !== ''
            && trim((string) $s->sslcommerz_store_password) !== '';
    }

    /**
     * Create a payment session and return the hosted checkout URL + the
     * server-generated tran_id (the unguessable reference the callbacks and
     * IPN resolve the pending order by).
     *
     * @param  array{name: string, email: string, phone: string, address: string, city: string, state: string, zip: string, country: string}  $customer
     * @return array{url: string, tran_id: string}
     */
    public function initializeSession(
        float $amount,
        string $currency,
        array $customer,
        array $metadata,
        string $successUrl,
        string $failUrl,
        string $cancelUrl,
        string $ipnUrl,
        string $productName
    ): array {
        $s = $this->settings();
        $tranId = 'sslc_'.bin2hex(random_bytes(16));

        $response = Http::asForm()->post($this->getBaseUrl().'/gwprocess/v4/api.php', [
            'store_id' => (string) $s->sslcommerz_store_id,
            'store_passwd' => (string) $s->sslcommerz_store_password,
            'total_amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper($currency),
            'tran_id' => $tranId,
            'success_url' => $successUrl,
            'fail_url' => $failUrl,
            'cancel_url' => $cancelUrl,
            'ipn_url' => $ipnUrl,
            'emi_option' => 0,
            'cus_name' => mb_substr($customer['name'] ?: 'Customer', 0, 100),
            'cus_email' => mb_substr($customer['email'], 0, 100),
            'cus_add1' => mb_substr($customer['address'] ?: 'N/A', 0, 100),
            'cus_city' => mb_substr($customer['city'] ?: 'N/A', 0, 50),
            'cus_state' => mb_substr($customer['state'], 0, 50),
            'cus_postcode' => mb_substr($customer['zip'] ?: 'N/A', 0, 30),
            'cus_country' => mb_substr($customer['country'] ?: 'Bangladesh', 0, 50),
            'cus_phone' => mb_substr($customer['phone'] ?: 'N/A', 0, 20),
            'shipping_method' => 'NO',
            'num_of_item' => 1,
            'product_name' => mb_substr($productName, 0, 255),
            'product_category' => 'General',
            'product_profile' => 'general',
            // value_a is echoed back on the IPN — the metadata order lookup.
            'value_a' => (string) ($metadata['online_order_id'] ?? ''),
            'value_b' => (string) ($metadata['client_id'] ?? ''),
        ]);

        $data = $response->json();
        if (! $response->successful() || strtoupper((string) ($data['status'] ?? '')) !== 'SUCCESS' || empty($data['GatewayPageURL'])) {
            Log::error('SSLCommerz session init failed', [
                'reason' => $data['failedreason'] ?? null,
                'response' => $data,
            ]);
            throw new \RuntimeException('Failed to initialize SSLCommerz session.');
        }

        return [
            'url' => $data['GatewayPageURL'],
            'tran_id' => $tranId,
        ];
    }

    /**
     * Validate a transaction server-side by val_id (validationserverAPI).
     * MUST be called when the customer returns / the IPN arrives — a forged
     * POST must never mark an order paid. The validated tran_id has to match
     * the order's stored one (a replayed val_id from a different, cheaper
     * transaction is rejected there), and the amount is cross-checked when
     * the validated currency equals the requested one.
     *
     * @return array{success: bool, tran_id: string, val_id: ?string, bank_tran_id: ?string, status: ?string}
     */
    public function validateTransaction(string $valId, string $expectedTranId, float $expectedAmount, string $expectedCurrency): array
    {
        $s = $this->settings();

        $response = Http::get($this->getBaseUrl().'/validator/api/validationserverAPI.php', [
            'val_id' => $valId,
            'store_id' => (string) $s->sslcommerz_store_id,
            'store_passwd' => (string) $s->sslcommerz_store_password,
            'format' => 'json',
            'v' => 1,
        ]);

        $data = $response->json() ?: [];
        $status = strtoupper((string) ($data['status'] ?? ''));
        $fail = ['success' => false, 'tran_id' => $expectedTranId, 'val_id' => $valId, 'bank_tran_id' => null, 'status' => $status ?: null];

        if (! $response->successful() || ! in_array($status, ['VALID', 'VALIDATED'], true)) {
            Log::warning('SSLCommerz validation not VALID', ['val_id' => $valId, 'response' => $data]);

            return $fail;
        }

        if ((string) ($data['tran_id'] ?? '') !== $expectedTranId) {
            Log::error('SSLCommerz validation tran_id mismatch', [
                'expected' => $expectedTranId,
                'validated' => $data['tran_id'] ?? null,
            ]);

            return $fail;
        }

        // currency_type echoes the currency the session was opened with and
        // currency_amount the charge in it. This guard FAILS CLOSED: an
        // unexpected currency or a deviating amount both reject — a replayed
        // val_id for a cheaper or differently-denominated transaction must
        // never mark the order paid.
        $validatedCurrency = strtoupper((string) ($data['currency_type'] ?? $data['currency'] ?? ''));
        $validatedAmount = (float) ($data['currency_amount'] ?? $data['amount'] ?? 0);
        if ($validatedCurrency !== strtoupper($expectedCurrency) || abs($validatedAmount - $expectedAmount) > 0.05) {
            Log::error('SSLCommerz validation currency/amount mismatch', [
                'expected_currency' => strtoupper($expectedCurrency),
                'validated_currency' => $validatedCurrency,
                'expected_amount' => $expectedAmount,
                'validated_amount' => $validatedAmount,
                'tran_id' => $expectedTranId,
            ]);

            return $fail;
        }

        return [
            'success' => true,
            'tran_id' => (string) $data['tran_id'],
            'val_id' => (string) ($data['val_id'] ?? $valId),
            'bank_tran_id' => isset($data['bank_tran_id']) ? (string) $data['bank_tran_id'] : null,
            'status' => $status,
        ];
    }

    /**
     * Validate by tran_id (merchantTransIDvalidationAPI) — the recovery path
     * when a callback carries no val_id (lost redirect, retried return).
     *
     * @return array{success: bool, tran_id: string, val_id: ?string, bank_tran_id: ?string, status: ?string}
     */
    public function validateByTranId(string $tranId, float $expectedAmount, string $expectedCurrency): array
    {
        $s = $this->settings();

        $response = Http::get($this->getBaseUrl().'/validator/api/merchantTransIDvalidationAPI.php', [
            'tran_id' => $tranId,
            'store_id' => (string) $s->sslcommerz_store_id,
            'store_passwd' => (string) $s->sslcommerz_store_password,
            'format' => 'json',
        ]);

        $data = $response->json() ?: [];
        foreach ((array) ($data['element'] ?? []) as $trans) {
            $status = strtoupper((string) ($trans['status'] ?? ''));
            if (in_array($status, ['VALID', 'VALIDATED'], true) && ! empty($trans['val_id'])) {
                // Re-validate the found val_id through the primary API so the
                // amount/tran_id cross-checks always run on the same path.
                return $this->validateTransaction((string) $trans['val_id'], $tranId, $expectedAmount, $expectedCurrency);
            }
        }

        return ['success' => false, 'tran_id' => $tranId, 'val_id' => null, 'bank_tran_id' => null, 'status' => null];
    }

    /**
     * Verify an incoming IPN (form-encoded POST). Unlike the other gateways
     * there is no signature HEADER: authentication is the verify_sign MD5
     * carried INSIDE the payload (verify_key fields + md5(store_passwd)).
     * A status of VALID is then additionally confirmed through the validation
     * API by the WebhookController before anything is marked paid — the
     * normalized result carries the val_id for that.
     *
     * @return array{valid: bool, event_type: string, gateway_payment_id: string, transaction_id: string, online_order_id: ?int, status: string}
     */
    public function verifyWebhook(string $payload): array
    {
        $result = [
            'valid' => false,
            'event_type' => '',
            'gateway_payment_id' => '',
            'transaction_id' => '',
            'online_order_id' => null,
            'status' => 'unknown',
        ];

        parse_str($payload, $post);
        if (! is_array($post) || empty($post['tran_id'])) {
            return $result;
        }

        // Primary security gate — reject on verify_sign mismatch.
        if (! $this->checkVerifySign($post)) {
            Log::error('SSLCommerz IPN verify_sign mismatch', ['tran_id' => $post['tran_id'] ?? null]);

            return $result;
        }

        $status = strtoupper((string) ($post['status'] ?? ''));
        $result['valid'] = true;
        $result['event_type'] = $status;
        $result['gateway_payment_id'] = (string) $post['tran_id'];
        $result['transaction_id'] = (string) ($post['val_id'] ?? '');
        $result['online_order_id'] = isset($post['value_a']) && $post['value_a'] !== '' ? (int) $post['value_a'] : null;

        $result['status'] = match ($status) {
            'VALID', 'VALIDATED' => 'paid',
            'FAILED' => 'failed',
            default => 'unknown', // CANCELLED, UNATTEMPTED, EXPIRED: leave to the browser handlers
        };

        return $result;
    }

    /**
     * SSLCommerz's IPN signature: md5 over the alphabetically-sorted fields
     * named in verify_key plus store_passwd=md5(store password).
     */
    private function checkVerifySign(array $post): bool
    {
        $sign = strtolower((string) ($post['verify_sign'] ?? ''));
        $keys = array_filter(explode(',', (string) ($post['verify_key'] ?? '')));
        if ($sign === '' || ! $keys) {
            return false;
        }

        $fields = [];
        foreach ($keys as $key) {
            $fields[$key] = (string) ($post[$key] ?? '');
        }
        $fields['store_passwd'] = md5((string) $this->settings()->sslcommerz_store_password);
        ksort($fields);

        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return hash_equals(md5(implode('&', $pairs)), $sign);
    }

    protected function getBaseUrl(): string
    {
        $sandbox = (bool) ($this->settings()->sslcommerz_sandbox ?? true);

        return $sandbox
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';
    }
}
