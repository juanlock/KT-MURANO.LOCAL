<?php

namespace App\Services;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * bKash (Tokenized Checkout, URL-based "0011" mode) for the online-store
 * checkout. Same redirect-then-verify pattern as the other store gateways:
 * create a payment for the SERVER-computed total, redirect the customer to
 * the hosted bkashURL, then EXECUTE server-side on the callback — the
 * redirect status alone is never trusted. bKash charges in BDT only, so the
 * checkout offers it only while the active store currency is BDT.
 *
 * Auth is a merchant-credential grant token (id_token, ~1h) cached per app
 * key, mirroring PayPalService's access-token handling. Credentials and the
 * sandbox/live switch live on the store_settings row. bKash has no
 * server-to-server webhook for this flow; a lost redirect is recovered by
 * the query endpoint on the next return-hit.
 */
class BkashService
{
    private ?StoreSetting $settings = null;

    private function settings(): ?StoreSetting
    {
        return $this->settings ??= StoreSetting::query()->first();
    }

    /** Enabled by the admin AND all four credentials present. */
    public function isConfigured(): bool
    {
        $s = $this->settings();

        return $s
            && (bool) $s->bkash_enabled
            && trim((string) $s->bkash_app_key) !== ''
            && trim((string) $s->bkash_app_secret) !== ''
            && trim((string) $s->bkash_username) !== ''
            && trim((string) $s->bkash_password) !== '';
    }

    /**
     * Create a tokenized-checkout payment for the SERVER-computed total and
     * return the hosted URL the customer must be redirected to. bKash appends
     * ?paymentID&status=success|failure|cancel to the callback URL.
     *
     * @return array{url: string, payment_id: string}
     */
    public function createPayment(
        float $amount,
        string $payerReference,
        string $invoiceNumber,
        string $callbackUrl
    ): array {
        $response = $this->api()->post($this->getBaseUrl().'/create', [
            'mode' => '0011',
            'payerReference' => mb_substr(trim($payerReference) !== '' ? $payerReference : $invoiceNumber, 0, 255),
            'callbackURL' => $callbackUrl,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $invoiceNumber,
        ]);

        $data = $response->json();
        if (! $response->successful() || ($data['statusCode'] ?? '') !== '0000' || empty($data['bkashURL'])) {
            Log::error('bKash payment create failed', ['response' => $data]);
            throw new \RuntimeException('Failed to create bKash payment.');
        }

        return [
            'url' => $data['bkashURL'],
            'payment_id' => $data['paymentID'],
        ];
    }

    /**
     * Execute an approved payment — the step that actually moves the money.
     * MUST be called on the callback; a forged redirect must never mark an
     * order paid.
     *
     * @return array{success: bool, payment_id: string, trx_id: ?string, status: ?string}
     */
    public function executePayment(string $paymentId): array
    {
        $response = $this->api()->post($this->getBaseUrl().'/execute', [
            'paymentID' => $paymentId,
        ]);

        $data = $response->json() ?: [];

        if (($data['statusCode'] ?? '') === '0000' && ($data['transactionStatus'] ?? '') === 'Completed') {
            return [
                'success' => true,
                'payment_id' => $data['paymentID'] ?? $paymentId,
                'trx_id' => $data['trxID'] ?? null,
                'status' => $data['transactionStatus'] ?? null,
            ];
        }

        Log::warning('bKash execute did not complete', ['payment_id' => $paymentId, 'response' => $data]);

        return ['success' => false, 'payment_id' => $paymentId, 'trx_id' => null, 'status' => $data['transactionStatus'] ?? null];
    }

    /**
     * Query a payment's status — the recovery path when execute times out or
     * a retried callback hits an already-executed payment.
     *
     * @return array{success: bool, payment_id: string, trx_id: ?string, status: ?string}
     */
    public function queryPayment(string $paymentId): array
    {
        $response = $this->api()->post($this->getBaseUrl().'/payment/status', [
            'paymentID' => $paymentId,
        ]);

        $data = $response->json() ?: [];

        return [
            'success' => ($data['statusCode'] ?? '') === '0000' && ($data['transactionStatus'] ?? '') === 'Completed',
            'payment_id' => $data['paymentID'] ?? $paymentId,
            'trx_id' => $data['trxID'] ?? null,
            'status' => $data['transactionStatus'] ?? null,
        ];
    }

    /** Pending request with the tokenized-checkout auth headers applied. */
    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $this->getIdToken(),
            'X-APP-Key' => (string) $this->settings()->bkash_app_key,
        ])->acceptJson();
    }

    /**
     * Grant token (id_token), cached until shortly before bKash expires it.
     * Keyed by app key so swapping credentials never reuses a stale token.
     */
    protected function getIdToken(): string
    {
        $s = $this->settings();
        $cacheKey = 'store_bkash_token_'.md5((string) $s->bkash_app_key.'|'.$this->getBaseUrl());

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($s) {
            $response = Http::withHeaders([
                'username' => (string) $s->bkash_username,
                'password' => (string) $s->bkash_password,
            ])->acceptJson()->post($this->getBaseUrl().'/token/grant', [
                'app_key' => (string) $s->bkash_app_key,
                'app_secret' => (string) $s->bkash_app_secret,
            ]);

            $data = $response->json();
            if (! $response->successful() || empty($data['id_token'])) {
                Log::error('bKash token grant failed', ['response' => $data]);
                throw new \RuntimeException('Failed to obtain bKash grant token.');
            }

            return (string) $data['id_token'];
        });
    }

    protected function getBaseUrl(): string
    {
        $sandbox = (bool) ($this->settings()->bkash_sandbox ?? true);

        return $sandbox
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';
    }
}
