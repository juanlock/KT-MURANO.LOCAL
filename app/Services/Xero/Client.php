<?php

namespace App\Services\Xero;

use App\Models\XeroSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Xero Accounting API (api.xero.com/api.xro/2.0).
 *
 * Token lifecycle (developer.xero.com, verified Aug 2026): access tokens last
 * 30 minutes; refresh tokens last 60 days and ROTATE on every use (the old
 * pair is invalidated, with a ~30-minute retry grace). Refreshes are
 * serialized behind a cache lock and the settings row is re-read inside it.
 * Every API request carries the connected organisation's Xero-Tenant-Id.
 */
class Client
{
    public const API_BASE = 'https://api.xero.com/api.xro/2.0/';
    public const AUTH_URL = 'https://login.xero.com/identity/connect/authorize';
    public const TOKEN_URL = 'https://identity.xero.com/connect/token';
    public const CONNECTIONS_URL = 'https://api.xero.com/connections';

    public function __construct(private XeroSetting $settings)
    {
    }

    public static function make(?XeroSetting $settings = null): self
    {
        return new self($settings ?: XeroSetting::current());
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->request('get', $path, $query);
    }

    public function post(string $path, array $data = []): Response
    {
        return $this->request('post', $path, $data);
    }

    public function put(string $path, array $data = []): Response
    {
        return $this->request('put', $path, $data);
    }

    /** Human-readable error out of a Xero error payload. */
    public static function error(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $messages = [];
            foreach ($json['Elements'] ?? [] as $element) {
                foreach ($element['ValidationErrors'] ?? [] as $validation) {
                    $messages[] = (string) ($validation['Message'] ?? '');
                }
            }
            $messages = array_filter($messages);
            if ($messages) {
                return implode('; ', array_slice($messages, 0, 3));
            }
            foreach (['Detail', 'Message', 'Title', 'error_description', 'error'] as $key) {
                if (! empty($json[$key]) && is_string($json[$key])) {
                    return $json[$key];
                }
            }
        }

        return 'HTTP '.$response->status();
    }

    /** Exchange an authorization code for the token pair. Returns the raw JSON. */
    public static function exchangeCode(XeroSetting $settings, string $code, string $redirectUri): array
    {
        $response = Http::withBasicAuth((string) $settings->client_id, (string) $settings->client_secret)
            ->asForm()->timeout(30)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Xero token exchange failed: '.self::error($response));
        }

        return (array) $response->json();
    }

    /** Persist a token payload from a code exchange or refresh. */
    public static function storeTokens(XeroSetting $settings, array $tokens): void
    {
        $settings->access_token = (string) ($tokens['access_token'] ?? '') ?: $settings->access_token;
        if (! empty($tokens['refresh_token'])) {
            // Rotating: always keep the NEWEST refresh token.
            $settings->refresh_token = (string) $tokens['refresh_token'];
        }
        $settings->access_token_expires_at = now()->addSeconds((int) ($tokens['expires_in'] ?? 1800));
        $settings->save();
    }

    /** The organisations ("tenants") this token pair is connected to. */
    public static function fetchConnections(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->acceptJson()->timeout(30)->get(self::CONNECTIONS_URL);
        if (! $response->successful()) {
            throw new RuntimeException('Xero connections lookup failed: '.self::error($response));
        }

        return (array) $response->json();
    }

    private function request(string $method, string $path, array $data = []): Response
    {
        $token = $this->freshAccessToken();
        $url = self::API_BASE.ltrim($path, '/');

        $send = fn (string $accessToken) => Http::withToken($accessToken)
            ->withHeaders(['Xero-Tenant-Id' => (string) $this->settings->tenant_id])
            ->acceptJson()
            ->timeout(30)
            ->{$method}($url, $data);

        $response = $send($token);

        // A 401 can mean the token was revoked out-of-band — force one refresh and retry.
        if ($response->status() === 401 && ! empty($this->settings->refresh_token)) {
            $response = $send($this->freshAccessToken(true));
        }

        return $response;
    }

    private function freshAccessToken(bool $force = false): string
    {
        $settings = $this->settings;

        if (empty($settings->refresh_token)) {
            throw new RuntimeException('Xero is not connected — connect the organisation first.');
        }

        $stillValid = fn (XeroSetting $s) => ! empty($s->access_token)
            && $s->access_token_expires_at
            && now()->addSeconds(60)->lt($s->access_token_expires_at);

        if (! $force && $stillValid($settings)) {
            return $settings->access_token;
        }

        // Serialize refreshes: Xero rotates the refresh token on every use.
        $lock = Cache::lock('xero-token-refresh', 30);
        try {
            $lock->block(15);

            // Another process may have refreshed while we waited.
            $settings->refresh();
            if (! $force && $stillValid($settings)) {
                return $settings->access_token;
            }

            $response = Http::withBasicAuth((string) $settings->client_id, (string) $settings->client_secret)
                ->asForm()->timeout(30)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => (string) $settings->refresh_token,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Xero token refresh failed: '.self::error($response));
            }

            self::storeTokens($settings, (array) $response->json());

            if (empty($settings->access_token)) {
                throw new RuntimeException('Xero token refresh returned no access token.');
            }

            return $settings->access_token;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                // Lock may have expired while the HTTP call ran; nothing to do.
            }
        }
    }
}
