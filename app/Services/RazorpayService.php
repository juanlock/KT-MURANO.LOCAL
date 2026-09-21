<?php

namespace App\Services;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Razorpay (Payment Links API) for the online-store checkout.
 *
 * Razorpay has no Stocky-SaaS counterpart to port, so this follows the same
 * redirect-then-verify pattern as the PayPal/Paystack/Flutterwave gateways:
 * create a Payment Link server-side for the SERVER-computed total, redirect
 * the customer to the hosted short_url, then VERIFY by fetching the link
 * from the API on the callback — the redirect params are never trusted.
 * Webhooks are HMAC-SHA256 signed (X-Razorpay-Signature) with the separate
 * webhook secret defined when registering the webhook on the dashboard.
 * Test vs live is decided by the key pair (rzp_test_/rzp_live_), so there
 * is no sandbox switch.
 */
class RazorpayService
{
    protected const API_BASE = 'https://api.razorpay.com/v1';

    private ?StoreSetting $settings = null;

    private function settings(): ?StoreSetting
    {
        return $this->settings ??= StoreSetting::query()->first();
    }

    /** Enabled by the admin AND both keys present. */
    public function isConfigured(): bool
    {
        $s = $this->settings();

        return $s
            && (bool) $s->razorpay_enabled
            && trim((string) $s->razorpay_key_id) !== ''
            && trim((string) $s->razorpay_key_secret) !== '';
    }

    /**
     * Create a Payment Link and return the hosted short_url + link id.
     *
     * @return array{url: string, id: string}
     */
    public function createPaymentLink(
        float $amount,
        string $currency,
        string $description,
        string $customerName,
        string $customerEmail,
        array $metadata,
        string $callbackUrl
    ): array {
        $response = null;
        try {
            $response = $this->client()
                ->post(self::API_BASE.'/payment_links', [
                    'amount' => (int) round($amount * 100), // subunits (paise)
                    'currency' => strtoupper($currency),
                    'description' => $description,
                    'reference_id' => 'onl-'.($metadata['online_order_id'] ?? bin2hex(random_bytes(6))),
                    'customer' => [
                        'name' => $customerName !== '' ? $customerName : 'Customer',
                        'email' => $customerEmail,
                    ],
                    // The store sends its own order emails — keep Razorpay quiet.
                    'notify' => ['sms' => false, 'email' => false],
                    'notes' => $metadata,
                    'callback_url' => $callbackUrl,
                    'callback_method' => 'get',
                ]);
        } catch (\Throwable $e) {
            Log::error('Razorpay payment link request failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Could not connect to Razorpay: '.$e->getMessage());
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['short_url']) || empty($body['id'])) {
            Log::error('Razorpay payment link creation failed', [
                'http_status' => $response->status(),
                'response' => $body,
            ]);
            throw new \RuntimeException(
                'Razorpay: '.($body['error']['description'] ?? 'Failed to create payment link.')
            );
        }

        return ['url' => $body['short_url'], 'id' => $body['id']];
    }

    /**
     * Verify a Payment Link server-side by fetching it from the API. MUST be
     * called on the callback — the redirect query params are never trusted.
     *
     * @return array{success: bool, status: ?string, payment_id: string}
     */
    public function verifyPaymentLink(string $linkId): array
    {
        try {
            $response = $this->client()->get(self::API_BASE.'/payment_links/'.$linkId);
        } catch (\Throwable $e) {
            Log::error('Razorpay payment link verify failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'status' => null, 'payment_id' => ''];
        }

        $body = $response->json() ?? [];
        if (! $response->successful()) {
            Log::error('Razorpay payment link verify failed', [
                'link_id' => $linkId,
                'response' => $body,
            ]);

            return ['success' => false, 'status' => null, 'payment_id' => ''];
        }

        $status = strtolower($body['status'] ?? '');
        $paymentId = '';
        foreach ((array) ($body['payments'] ?? []) as $p) {
            if (($p['status'] ?? '') === 'captured') {
                $paymentId = (string) ($p['payment_id'] ?? '');
                break;
            }
        }

        return [
            'success' => $status === 'paid',
            'status' => $status ?: null,
            'payment_id' => $paymentId,
        ];
    }

    /**
     * Verify an incoming webhook (HMAC-SHA256 over the raw payload with the
     * dashboard-defined webhook secret; X-Razorpay-Signature header) and
     * normalize the event. A missing configured secret REJECTS the event —
     * an unsigned webhook must never mark orders paid.
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

        $secret = trim((string) ($this->settings()->razorpay_webhook_secret ?? ''));
        if ($secret === '') {
            Log::warning('Razorpay webhook received but no Webhook Secret is configured.');

            return $result;
        }
        if (! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            Log::error('Razorpay webhook signature mismatch');

            return $result;
        }

        $body = json_decode($payload, true);
        if (! $body) {
            return $result;
        }

        $result['valid'] = true;
        $result['event_type'] = $body['event'] ?? '';

        $payloadData = $body['payload'] ?? [];
        $payment = $payloadData['payment']['entity'] ?? [];
        $link = $payloadData['payment_link']['entity'] ?? [];
        $refund = $payloadData['refund']['entity'] ?? [];

        $notes = [];
        foreach ([$link['notes'] ?? null, $payment['notes'] ?? null] as $candidate) {
            if (is_array($candidate) && $candidate) {
                $notes = $candidate;
                break;
            }
        }

        // ── Successful payment ──
        if (in_array($result['event_type'], ['payment_link.paid', 'payment.captured'], true)) {
            $result['gateway_payment_id'] = (string) ($link['id'] ?? '');
            $result['transaction_id'] = (string) ($payment['id'] ?? '');
            $result['online_order_id'] = isset($notes['online_order_id']) ? (int) $notes['online_order_id'] : null;
            $result['status'] = 'paid';
        }

        // ── Failed payment ──
        elseif ($result['event_type'] === 'payment.failed') {
            $result['gateway_payment_id'] = (string) ($link['id'] ?? '');
            $result['transaction_id'] = (string) ($payment['id'] ?? '');
            $result['online_order_id'] = isset($notes['online_order_id']) ? (int) $notes['online_order_id'] : null;
            $result['status'] = 'failed';
        }

        // ── Refunds (refund entity points at the original payment) ──
        elseif (in_array($result['event_type'], ['refund.processed', 'refund.created'], true)) {
            $result['gateway_payment_id'] = (string) ($refund['id'] ?? '');
            $result['transaction_id'] = (string) ($refund['payment_id'] ?? '');
            $result['status'] = 'refunded';
        }

        return $result;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $s = $this->settings();

        return Http::withBasicAuth(
            trim((string) $s->razorpay_key_id),
            trim((string) $s->razorpay_key_secret)
        )->timeout(30);
    }
}
