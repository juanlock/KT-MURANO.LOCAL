<?php

namespace App\Services\Jumia;

use App\Models\JumiaSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Jumia Seller Center API (the SellerCenter
 * protocol). Every request carries Action/Format/Timestamp/UserID/Version
 * query params plus a Signature: HMAC-SHA256 (hex) over the RFC 3986-encoded,
 * alphabetically sorted parameter string, keyed with the seller's API key.
 * Reads answer JSON (Format=JSON); product feeds POST an XML body.
 */
class Client
{
    public function __construct(private JumiaSetting $settings)
    {
    }

    public static function make(?JumiaSetting $settings = null): self
    {
        return new self($settings ?: JumiaSetting::current());
    }

    /** GET action (JSON response). */
    public function call(string $action, array $params = []): Response
    {
        return Http::acceptJson()
            ->timeout(45)
            ->get(rtrim((string) $this->settings->api_url, '/').'/', $this->signedParams($action, $params));
    }

    /** POST action with an XML feed body (JSON response envelope). */
    public function feed(string $action, string $xml, array $params = []): Response
    {
        $query = http_build_query($this->signedParams($action, $params), '', '&', PHP_QUERY_RFC3986);

        return Http::withBody($xml, 'text/xml')
            ->acceptJson()
            ->timeout(45)
            ->post(rtrim((string) $this->settings->api_url, '/').'/?'.$query);
    }

    /** The SuccessResponse Body, or throws with the ErrorResponse message. */
    public static function body(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Jumia returned a non-JSON response (HTTP '.$response->status().')');
        }
        if (isset($json['ErrorResponse'])) {
            $head = $json['ErrorResponse']['Head'] ?? [];
            throw new RuntimeException(trim(($head['ErrorCode'] ?? '').' '.($head['ErrorMessage'] ?? 'Jumia error')));
        }
        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }

        return (array) ($json['SuccessResponse']['Body'] ?? []);
    }

    /**
     * SellerCenter JSON collapses single-element collections into an object:
     * Body.Orders.Order may be a list of orders OR one order. Normalize.
     */
    public static function listOf(array $container, string $wrapper, string $item): array
    {
        $inner = $container[$wrapper] ?? [];
        if (! is_array($inner)) {
            return [];
        }
        $rows = $inner[$item] ?? $inner;
        if (! is_array($rows)) {
            return [];
        }

        // A single object (string keys) vs a list (numeric keys).
        return array_is_list($rows) ? $rows : [$rows];
    }

    private function signedParams(string $action, array $params): array
    {
        if (! $this->settings->isConfigured()) {
            throw new RuntimeException('Jumia is not configured — add the API URL, email and key first.');
        }

        $params = array_merge($params, [
            'Action' => $action,
            'Format' => 'JSON',
            'Timestamp' => now()->toIso8601String(),
            'UserID' => (string) $this->settings->user_email,
            'Version' => '1.0',
        ]);
        ksort($params);

        $encoded = [];
        foreach ($params as $key => $value) {
            $encoded[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }
        $params['Signature'] = hash_hmac('sha256', implode('&', $encoded), (string) $this->settings->api_key);

        return $params;
    }
}
