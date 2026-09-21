<?php

namespace App\Services;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flutterwave v3 (Standard hosted payments) for the online-store checkout.
 *
 * Ported from the Stocky SaaS FlutterwaveGateway and follows the same rules:
 * ALWAYS the v3 API (https://api.flutterwave.com/v3) with classic keys
 * (FLWPUBK_/FLWSECK_) — never v4, never OAuth. Flow: initialize a payment
 * with a server-generated tx_ref and the SERVER-computed total, redirect to
 * the hosted link, then VERIFY by tx_ref on the redirect back. Webhooks are
 * checked against the Secret Hash sent in the `verif-hash` header. Test vs
 * live is decided by the key pair, so there is no sandbox switch.
 *
 * One deliberate difference from SaaS: the DCC currency fallback (retry in
 * the account currency with a CONVERTED amount) is not ported — this app has
 * no exchange-rate service, and retrying with an unconverted amount would
 * charge the wrong price. Unsupported currencies fail cleanly instead.
 */
class FlutterwaveService
{
    /** Flutterwave v3 API base URL — never change to /v4. */
    protected const API_BASE = 'https://api.flutterwave.com/v3';

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
            && (bool) $s->flutterwave_enabled
            && trim((string) $s->flutterwave_public_key) !== ''
            && trim((string) $s->flutterwave_secret_key) !== '';
    }

    /**
     * Initialize a v3 Standard payment and return the hosted link + tx_ref.
     *
     * @return array{url: string, tx_ref: string}
     */
    public function initializePayment(
        float $amount,
        string $currency,
        string $email,
        string $name,
        string $description,
        array $metadata,
        string $redirectUrl
    ): array {
        $txRef = 'flw'.bin2hex(random_bytes(16));

        $response = null;
        try {
            $response = Http::withToken($this->secretKey())
                ->timeout(30)
                ->post(self::API_BASE.'/payments', [
                    'tx_ref' => $txRef,
                    'amount' => $amount,
                    'currency' => strtoupper($currency),
                    'redirect_url' => $redirectUrl,
                    'customer' => [
                        'email' => $email,
                        'name' => $name !== '' ? $name : 'Customer',
                    ],
                    'customizations' => [
                        'title' => (string) ($this->settings()->store_name ?? config('app.name')),
                        'description' => $description,
                    ],
                    'meta' => $metadata,
                ]);
        } catch (\Throwable $e) {
            Log::error('Flutterwave v3 payment init request failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Could not connect to Flutterwave: '.$e->getMessage());
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['status'] ?? '') !== 'success') {
            Log::error('Flutterwave v3 payment init failed', [
                'http_status' => $response->status(),
                'currency' => strtoupper($currency),
                'response' => $body,
            ]);
            throw new \RuntimeException('Flutterwave: '.$this->extractMessage($body));
        }

        $link = $body['data']['link'] ?? '';
        if ($link === '') {
            throw new \RuntimeException('Flutterwave did not return a checkout link.');
        }

        return ['url' => $link, 'tx_ref' => $txRef];
    }

    /**
     * Verify a transaction by tx_ref (v3 verify_by_reference). MUST be called
     * on the redirect back — the redirect's own status is never trusted.
     *
     * @return array{success: bool, status: ?string, transaction_id: string}
     */
    public function verifyTransaction(string $txRef): array
    {
        try {
            $response = Http::withToken($this->secretKey())
                ->timeout(15)
                ->get(self::API_BASE.'/transactions/verify_by_reference', [
                    'tx_ref' => $txRef,
                ]);
        } catch (\Throwable $e) {
            Log::error('Flutterwave v3 verify_by_reference failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'status' => null, 'transaction_id' => ''];
        }

        $data = ($response->json() ?? [])['data'] ?? [];
        $status = strtolower($data['status'] ?? '');

        return [
            'success' => in_array($status, ['successful', 'succeeded', 'completed'], true),
            'status' => $status ?: null,
            'transaction_id' => (string) ($data['id'] ?? ''),
        ];
    }

    /**
     * Verify an incoming webhook via the Secret Hash (`verif-hash` header)
     * and normalize the event. Unlike the SaaS version, a missing configured
     * hash REJECTS the event — an unsigned webhook must never mark orders
     * paid.
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

        $secretHash = trim((string) ($this->settings()->flutterwave_secret_hash ?? ''));
        if ($secretHash === '') {
            Log::warning('Flutterwave webhook received but no Secret Hash is configured.');

            return $result;
        }
        if (! hash_equals($secretHash, $signature)) {
            Log::error('Flutterwave webhook hash mismatch');

            return $result;
        }

        $body = json_decode($payload, true);
        if (! $body) {
            return $result;
        }

        $result['valid'] = true;
        $result['event_type'] = $body['event'] ?? '';

        $data = $body['data'] ?? [];
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $chargeStatus = strtolower($data['status'] ?? '');

        if ($result['event_type'] === 'charge.completed'
            && in_array($chargeStatus, ['successful', 'succeeded', 'completed'], true)) {
            $result['gateway_payment_id'] = $data['tx_ref'] ?? '';
            $result['transaction_id'] = (string) ($data['id'] ?? $data['tx_ref'] ?? '');
            $result['online_order_id'] = isset($meta['online_order_id']) ? (int) $meta['online_order_id'] : null;
            $result['status'] = 'paid';
        } elseif ($result['event_type'] === 'charge.completed' && $chargeStatus === 'failed') {
            $result['gateway_payment_id'] = $data['tx_ref'] ?? '';
            $result['transaction_id'] = (string) ($data['id'] ?? '');
            $result['online_order_id'] = isset($meta['online_order_id']) ? (int) $meta['online_order_id'] : null;
            $result['status'] = 'failed';
        }

        return $result;
    }

    /** Safely extract a string error message from a v3 response body. */
    protected function extractMessage(array $body, string $fallback = 'Unknown error'): string
    {
        foreach (['message', 'error_description', 'error', 'detail'] as $key) {
            if (! isset($body[$key])) {
                continue;
            }
            if (is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
            if (is_array($body[$key])) {
                return json_encode($body[$key]);
            }
        }

        return $fallback;
    }

    private function secretKey(): string
    {
        return trim((string) ($this->settings()->flutterwave_secret_key ?? ''));
    }
}
