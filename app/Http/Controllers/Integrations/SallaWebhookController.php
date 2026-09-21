<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\SallaSetting;
use App\Services\Salla\SyncService;
use Illuminate\Http\Request;

/**
 * Public Salla webhook endpoint. Every request is authenticated by its
 * X-Salla-Signature header: a hex HMAC-SHA256 of the raw body computed with
 * the webhook secret from the Salla Partners portal ("Signature" strategy).
 * Payload envelope: { event, merchant, created_at, data }.
 */
class SallaWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $settings = SallaSetting::query()->first();
        if (! $settings) {
            return response()->json(['ok' => false], 404);
        }

        $rawBody = $request->getContent();
        if (! $this->verifySignature($settings, $rawBody, (string) $request->header('X-Salla-Signature', ''))) {
            try {
                SyncService::make($settings)->log('webhook.rejected', 'warning', 'Webhook rejected: invalid signature');
            } catch (\Throwable $e) {
                // Logging table may not exist yet.
            }

            return response()->json(['ok' => false, 'error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'Invalid payload'], 400);
        }

        $event = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        try {
            SyncService::make($settings)->handleWebhook($event, $data);
        } catch (\Throwable $e) {
            try {
                SyncService::make($settings)->log('webhook.error', 'error', $e->getMessage(), ['event' => $event]);
            } catch (\Throwable $ignored) {
            }
        }

        // Return 200 regardless so Salla does not retry a payload that will keep failing.
        return response()->json(['ok' => true]);
    }

    private function verifySignature(SallaSetting $settings, string $rawBody, string $signatureHeader): bool
    {
        $secret = (string) $settings->webhook_secret;
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($computed, strtolower(trim($signatureHeader)));
    }
}
