<?php

namespace App\Services\Salla;

use App\Models\SallaSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Salla Merchant API (https://api.salla.dev/admin/v2).
 *
 * Token lifecycle (verified against docs.salla.dev, Aug 2026): access tokens
 * last 14 days; refresh tokens last 1 month and are SINGLE-USE — refreshing
 * twice with the same token revokes BOTH tokens. Refreshes are therefore
 * serialized behind a cache lock and the settings row is re-read inside it.
 */
class Client
{
    public const API_BASE = 'https://api.salla.dev/admin/v2/';
    public const AUTH_URL = 'https://accounts.salla.sa/oauth2/auth';
    public const TOKEN_URL = 'https://accounts.salla.sa/oauth2/token';

    /** Fallback access-token lifetime when the response omits expires_in. */
    private const ACCESS_TOKEN_TTL = 14 * 24 * 3600;

    public function __construct(private SallaSetting $settings)
    {
    }

    public static function make(?SallaSetting $settings = null): self
    {
        return new self($settings ?: SallaSetting::current());
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

    public function delete(string $path, array $data = []): Response
    {
        return $this->request('delete', $path, $data);
    }

    /** Human-readable error out of a Salla error envelope. */
    public static function error(Response $response): string
    {
        $json = $response->json();
        $error = is_array($json) ? ($json['error'] ?? null) : null;

        if (is_array($error)) {
            $message = (string) ($error['message'] ?? '');
            $fields = $error['fields'] ?? null;
            if (is_array($fields) && $fields) {
                $flat = [];
                foreach ($fields as $field => $problems) {
                    $flat[] = $field.': '.implode(', ', array_map('strval', (array) $problems));
                }
                $message = trim($message.' — '.implode('; ', $flat), ' —');
            }
            if ($message !== '') {
                return $message;
            }
        }

        return 'HTTP '.$response->status();
    }

    /** Exchange an authorization code for the token pair. Returns the raw JSON. */
    public static function exchangeCode(SallaSetting $settings, string $code, string $redirectUri): array
    {
        $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => (string) $settings->client_id,
            'client_secret' => (string) $settings->client_secret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Salla token exchange failed: '.self::error($response));
        }

        return (array) $response->json();
    }

    /** Persist a token payload (code exchange, refresh, or app.store.authorize webhook). */
    public static function storeTokens(SallaSetting $settings, array $tokens): void
    {
        $settings->access_token = (string) ($tokens['access_token'] ?? '') ?: $settings->access_token;
        if (! empty($tokens['refresh_token'])) {
            $settings->refresh_token = (string) $tokens['refresh_token'];
        }

        // 'expires_in' (seconds) on OAuth responses; 'expires' (unix ts) on the
        // app.store.authorize webhook payload.
        if (! empty($tokens['expires_in'])) {
            $settings->access_token_expires_at = now()->addSeconds((int) $tokens['expires_in']);
        } elseif (! empty($tokens['expires'])) {
            $settings->access_token_expires_at = \Carbon\Carbon::createFromTimestamp((int) $tokens['expires']);
        } else {
            $settings->access_token_expires_at = now()->addSeconds(self::ACCESS_TOKEN_TTL);
        }
        $settings->refresh_token_expires_at = now()->addMonth();
        $settings->save();
    }

    private function request(string $method, string $path, array $data = []): Response
    {
        $token = $this->freshAccessToken();
        $url = self::API_BASE.ltrim($path, '/');

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->{$method}($url, $data);

        // A 401 can mean the token was revoked out-of-band — force one refresh and retry.
        if ($response->status() === 401 && ! empty($this->settings->refresh_token)) {
            $token = $this->freshAccessToken(true);
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->{$method}($url, $data);
        }

        return $response;
    }

    private function freshAccessToken(bool $force = false): string
    {
        $settings = $this->settings;

        if (empty($settings->access_token)) {
            throw new RuntimeException('Salla is not connected — connect the store first.');
        }

        $stillValid = fn (SallaSetting $s) => $s->access_token_expires_at
            && now()->addSeconds(90)->lt($s->access_token_expires_at);

        if (! $force && $stillValid($settings)) {
            return $settings->access_token;
        }

        // Serialize refreshes: Salla refresh tokens are single-use, and two
        // parallel refreshes revoke the connection entirely.
        $lock = Cache::lock('salla-token-refresh', 30);
        try {
            $lock->block(15);

            // Another process may have refreshed while we waited.
            $settings->refresh();
            if (! $force && $stillValid($settings)) {
                return $settings->access_token;
            }

            if (empty($settings->refresh_token)) {
                throw new RuntimeException('Salla access token expired and no refresh token is stored — reconnect the store.');
            }

            $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $settings->refresh_token,
                'client_id' => (string) $settings->client_id,
                'client_secret' => (string) $settings->client_secret,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Salla token refresh failed: '.self::error($response));
            }

            self::storeTokens($settings, (array) $response->json());

            if (empty($settings->access_token)) {
                throw new RuntimeException('Salla token refresh returned no access token.');
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
