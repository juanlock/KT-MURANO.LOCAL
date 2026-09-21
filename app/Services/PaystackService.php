<?php

namespace App\Services;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack (Transactions API) for the online-store checkout.
 *
 * Ported from the Stocky SaaS PaystackGateway and follows the same payment
 * flow: initialize a transaction with a server-generated reference and the
 * SERVER-computed total (in subunits), redirect the customer to the hosted
 * authorization_url, then VERIFY server-side on the callback — never trust
 * the redirect alone. Test vs live is decided by the key pair (sk_test_ /
 * sk_live_), so there is no sandbox switch. Credentials live on the
 * store_settings row instead of the SaaS central payment_gateway_settings.
 */
class PaystackService
{
    private ?StoreSetting $settings = null;

    private function settings(): ?StoreSetting
    {
        return $this->settings ??= StoreSetting::query()->first();
    }

    /** Enabled by the admin AND both keys present (same rule as SaaS). */
    public function isConfigured(): bool
    {
        $s = $this->settings();

        return $s
            && (bool) $s->paystack_enabled
            && trim((string) $s->paystack_public_key) !== ''
            && trim((string) $s->paystack_secret_key) !== '';
    }

    /**
     * Initialize a transaction and return the hosted checkout URL + the
     * server-generated reference. Mirrors the SaaS gateway, including the
     * unsupported_currency retry (drop currency → account default).
     *
     * @return array{url: string, reference: string}
     */
    public function initializeTransaction(
        float $amount,
        string $currency,
        string $email,
        array $metadata,
        string $callbackUrl
    ): array {
        $reference = 'pstk_'.bin2hex(random_bytes(16));

        $payload = [
            'email' => $email,
            'amount' => (int) round($amount * 100), // Paystack expects subunits (kobo/pesewas)
            'currency' => strtoupper($currency),
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ];

        $response = Http::withToken($this->secretKey())
            ->post('https://api.paystack.co/transaction/initialize', $payload);

        // If the merchant's Paystack account doesn't support the requested
        // currency, retry without it so Paystack uses the account default.
        if (! $response->successful() && ($response->json('code') === 'unsupported_currency')) {
            unset($payload['currency']);
            $payload['metadata']['original_currency'] = strtoupper($currency);

            $response = Http::withToken($this->secretKey())
                ->post('https://api.paystack.co/transaction/initialize', $payload);
        }

        if (! $response->successful() || ! $response->json('status')) {
            Log::error('Paystack transaction init failed', ['response' => $response->json()]);
            throw new \RuntimeException('Failed to initialize Paystack transaction.');
        }

        $data = $response->json('data');

        return [
            'url' => $data['authorization_url'],
            'reference' => $data['reference'],
        ];
    }

    /**
     * Verify a transaction server-side using Paystack's Verify endpoint.
     * MUST be called when the customer returns from Paystack — a forged
     * redirect must never mark an order paid.
     *
     * @return array{success: bool, reference: string, transaction_id: ?string, status: ?string, amount: ?int, currency: ?string}
     */
    public function verifyTransaction(string $reference): array
    {
        $response = Http::withToken($this->secretKey())
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful() || ! $response->json('status')) {
            Log::error('Paystack transaction verification failed', [
                'reference' => $reference,
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'reference' => $reference,
                'transaction_id' => null,
                'status' => null,
                'amount' => null,
                'currency' => null,
            ];
        }

        $data = $response->json('data');

        return [
            'success' => ($data['status'] ?? '') === 'success',
            'reference' => $data['reference'] ?? $reference,
            'transaction_id' => isset($data['id']) ? (string) $data['id'] : null,
            'status' => $data['status'] ?? null,
            'amount' => $data['amount'] ?? null,   // in subunits
            'currency' => $data['currency'] ?? null,
        ];
    }

    /**
     * Verify an incoming webhook (HMAC-SHA512 over the raw payload with the
     * secret key — Paystack's x-paystack-signature header) and normalize the
     * event. Ported 1:1 from the SaaS gateway.
     *
     * @return array{valid: bool, event_type: string, gateway_payment_id: string, transaction_id: string, online_order_id: ?int, status: string}
     */
    public function verifyWebhook(string $payload, string $signature): array
    {
        $result = [
            'valid' => false,
            'event_type' => '',
            'gateway_payment_id' => '',
            'transaction_id' => '',
            'online_order_id' => null,
            'status' => 'unknown',
        ];

        // Primary security gate — reject on signature mismatch.
        $expectedSig = hash_hmac('sha512', $payload, $this->secretKey());
        if (! hash_equals($expectedSig, $signature)) {
            Log::error('Paystack webhook signature mismatch');

            return $result;
        }

        $body = json_decode($payload, true);
        if (! $body) {
            return $result;
        }

        $result['valid'] = true;
        $result['event_type'] = $body['event'] ?? '';

        $data = $body['data'] ?? [];
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        // ── Successful charge ──
        if ($result['event_type'] === 'charge.success') {
            $result['gateway_payment_id'] = $data['reference'] ?? '';
            $result['transaction_id'] = (string) ($data['id'] ?? $data['reference'] ?? '');
            $result['online_order_id'] = isset($meta['online_order_id']) ? (int) $meta['online_order_id'] : null;
            $result['status'] = 'paid';
        }

        // ── Failed charge ──
        elseif ($result['event_type'] === 'charge.failed') {
            $result['gateway_payment_id'] = $data['reference'] ?? '';
            $result['transaction_id'] = (string) ($data['id'] ?? $data['reference'] ?? '');
            $result['online_order_id'] = isset($meta['online_order_id']) ? (int) $meta['online_order_id'] : null;
            $result['status'] = 'failed';
        }

        // ── Refund processed (original txn nested under `transaction`) ──
        elseif ($result['event_type'] === 'refund.processed') {
            $transaction = $data['transaction'] ?? [];
            $refundMeta = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : $meta;

            $result['gateway_payment_id'] = (string) ($data['id'] ?? '');           // refund ID
            $result['transaction_id'] = (string) ($transaction['id'] ?? '');        // original txn ID
            $result['online_order_id'] = isset($refundMeta['online_order_id']) ? (int) $refundMeta['online_order_id'] : null;
            $result['status'] = 'refunded';
        }

        return $result;
    }

    private function secretKey(): string
    {
        return (string) ($this->settings()->paystack_secret_key ?? '');
    }
}
