<?php

namespace App\Services;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal (Orders API v2) for the online-store checkout.
 *
 * Ported from the Stocky SaaS PaypalGateway and follows the same payment
 * flow: create an order with intent CAPTURE + return/cancel URLs, redirect
 * the customer to the approve link, then capture on return (handling
 * ORDER_ALREADY_CAPTURED by re-reading the order). Credentials and the
 * sandbox/live switch live on the store_settings row instead of the SaaS
 * central payment_gateway_settings table.
 */
class PayPalService
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
            && (bool) $s->paypal_enabled
            && trim((string) $s->paypal_client_id) !== ''
            && trim((string) $s->paypal_client_secret) !== '';
    }

    /**
     * Create a PayPal order for the SERVER-computed total and return the
     * approve URL the customer must be redirected to.
     *
     * @return array{url: string, id: string}
     */
    public function createOrder(
        float $amount,
        string $currency,
        string $description,
        array $metadata,
        string $returnUrl,
        string $cancelUrl
    ): array {
        $response = Http::withToken($this->getAccessToken())
            ->withOptions(['verify' => $this->shouldVerifySsl()])
            ->post($this->getBaseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => strtoupper($currency),
                        'value' => number_format($amount, 2, '.', ''),
                    ],
                    'description' => $description,
                    'custom_id' => json_encode($metadata),
                ]],
                'application_context' => [
                    'brand_name' => (string) ($this->settings()->store_name ?? config('app.name')),
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ]);

        if (! $response->successful()) {
            Log::error('PayPal order creation failed', ['response' => $response->json()]);
            throw new \RuntimeException('Failed to create PayPal order.');
        }

        $order = $response->json();
        $approveLink = collect($order['links'] ?? [])->firstWhere('rel', 'approve');

        return [
            'url' => $approveLink['href'] ?? '',
            'id' => $order['id'],
        ];
    }

    /**
     * Capture an approved order. Mirrors the SaaS gateway, including the
     * ORDER_ALREADY_CAPTURED fallback (e.g. a retried return hit first).
     *
     * @return array{success: bool, order_id: string, capture_id: ?string, status: ?string}
     */
    public function captureOrder(string $orderId): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->withOptions(['verify' => $this->shouldVerifySsl()])
            ->withBody('{}', 'application/json')
            ->post($this->getBaseUrl()."/v2/checkout/orders/{$orderId}/capture");

        $data = $response->json();

        if (! $response->successful()) {
            $issue = $data['details'][0]['issue'] ?? '';
            if ($issue === 'ORDER_ALREADY_CAPTURED') {
                return $this->getOrderCapture($orderId);
            }

            Log::error('PayPal capture failed', [
                'order_id' => $orderId,
                'status' => $response->status(),
                'response' => $data,
            ]);

            return ['success' => false, 'order_id' => $orderId, 'capture_id' => null, 'status' => null];
        }

        $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;

        return [
            'success' => $capture && ($capture['status'] ?? '') === 'COMPLETED',
            'order_id' => $data['id'] ?? $orderId,
            'capture_id' => $capture['id'] ?? null,
            'status' => $capture['status'] ?? null,
        ];
    }

    /**
     * Fetch an already-completed order and return its capture details.
     */
    public function getOrderCapture(string $orderId): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->withOptions(['verify' => $this->shouldVerifySsl()])
            ->get($this->getBaseUrl()."/v2/checkout/orders/{$orderId}");

        if (! $response->successful()) {
            Log::error('PayPal get order failed', ['order_id' => $orderId, 'response' => $response->json()]);

            return ['success' => false, 'order_id' => $orderId, 'capture_id' => null, 'status' => null];
        }

        $data = $response->json();
        $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;

        return [
            'success' => ($data['status'] ?? '') === 'COMPLETED' && $capture,
            'order_id' => $data['id'] ?? $orderId,
            'capture_id' => $capture['id'] ?? null,
            'status' => $capture['status'] ?? null,
        ];
    }

    /**
     * Verify an incoming webhook via PayPal's verify-webhook-signature API
     * and normalize the event — ported 1:1 from the SaaS gateway (including
     * the deliberate exclusion of CHECKOUT.ORDER.APPROVED: approval fires
     * BEFORE capture, when no money has moved yet).
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

        $webhookId = trim((string) ($this->settings()->paypal_webhook_id ?? ''));
        if ($webhookId === '') {
            Log::warning('PayPal webhook received but no Webhook ID is configured.');

            return $result;
        }

        $body = json_decode($payload, true);
        if (! $body) {
            return $result;
        }

        try {
            $verification = Http::withToken($this->getAccessToken())
                ->withOptions(['verify' => $this->shouldVerifySsl()])
                ->post($this->getBaseUrl().'/v1/notifications/verify-webhook-signature', [
                    'auth_algo' => request()->header('PAYPAL-AUTH-ALGO', ''),
                    'cert_url' => request()->header('PAYPAL-CERT-URL', ''),
                    'transmission_id' => request()->header('PAYPAL-TRANSMISSION-ID', ''),
                    'transmission_sig' => $signature,
                    'transmission_time' => request()->header('PAYPAL-TRANSMISSION-TIME', ''),
                    'webhook_id' => $webhookId,
                    'webhook_event' => $body,
                ]);

            if (! $verification->successful() || ($verification->json('verification_status') !== 'SUCCESS')) {
                Log::error('PayPal webhook verification failed', ['response' => $verification->json()]);

                return $result;
            }
        } catch (\Throwable $e) {
            Log::error("PayPal webhook verification error: {$e->getMessage()}");

            return $result;
        }

        $result['valid'] = true;
        $result['event_type'] = $body['event_type'] ?? '';

        $resource = $body['resource'] ?? [];

        // ── Successful payment events (capture confirmed — money moved) ──
        if (in_array($result['event_type'], [
            'PAYMENT.CAPTURE.COMPLETED',
            'PAYMENT.SALE.COMPLETED',
        ], true)) {
            $rawCustom = $resource['purchase_units'][0]['custom_id']
                ?? $resource['custom_id']
                ?? $resource['custom']        // v1 SALE events
                ?? '{}';
            $meta = json_decode($rawCustom, true) ?: [];

            $result['gateway_payment_id'] = $resource['id'] ?? '';
            $result['transaction_id'] = $resource['id'] ?? '';
            $result['online_order_id'] = isset($meta['online_order_id']) ? (int) $meta['online_order_id'] : null;
            $result['status'] = 'paid';
        }

        // ── Refund / reversal events ──
        elseif (in_array($result['event_type'], [
            'PAYMENT.CAPTURE.REFUNDED',
            'PAYMENT.SALE.REFUNDED',
            'PAYMENT.SALE.REVERSED',
        ], true)) {
            // The refund resource carries no custom_id. For CAPTURE refunds the
            // "up" link points to the original capture (stored on our order as
            // paypal_capture_id); for SALE refunds resource.sale_id holds it.
            $captureId = null;
            if ($result['event_type'] === 'PAYMENT.CAPTURE.REFUNDED') {
                $upLink = collect($resource['links'] ?? [])->firstWhere('rel', 'up');
                if ($upLink) {
                    $captureId = basename(parse_url($upLink['href'], PHP_URL_PATH));
                }
            } else {
                $captureId = $resource['sale_id'] ?? $resource['id'] ?? null;
            }

            $result['transaction_id'] = (string) $captureId;
            $result['gateway_payment_id'] = (string) ($resource['id'] ?? '');
            $result['status'] = 'refunded';
        }

        // ── Failure / denial events ──
        elseif ($result['event_type'] === 'PAYMENT.CAPTURE.DENIED') {
            $result['gateway_payment_id'] = $resource['id'] ?? '';
            $result['status'] = 'failed';
        }

        return $result;
    }

    protected function getAccessToken(): string
    {
        $s = $this->settings();
        $baseUrl = $this->getBaseUrl();

        try {
            $response = Http::asForm()
                ->withBasicAuth((string) $s->paypal_client_id, (string) $s->paypal_client_secret)
                ->withOptions(['verify' => $this->shouldVerifySsl()])
                ->post($baseUrl.'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (\Throwable $e) {
            Log::error('PayPal access token request exception', [
                'url' => $baseUrl.'/v1/oauth2/token',
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to obtain PayPal access token: '.$e->getMessage());
        }

        if (! $response->successful()) {
            Log::error('PayPal access token request failed', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);
            throw new \RuntimeException(
                'Failed to obtain PayPal access token. HTTP '.$response->status()
                .': '.($response->json('error_description') ?? $response->body())
            );
        }

        return $response->json('access_token');
    }

    protected function shouldVerifySsl(): bool
    {
        // Always verify TLS for production PayPal domains. Only skip when
        // explicitly hitting the sandbox AND running on a local/dev env.
        $isSandbox = str_contains($this->getBaseUrl(), 'sandbox');

        return ! ($isSandbox && app()->environment(['local', 'development', 'testing']));
    }

    protected function getBaseUrl(): string
    {
        $testMode = (bool) ($this->settings()->paypal_test_mode ?? true);

        return $testMode
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }
}
