<?php

namespace App\Services\GoogleSheets;

use App\Models\GoogleSheetSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Google Sheets API v4.
 *
 * OAuth: access tokens last ~1 hour; refresh tokens do NOT rotate for web
 * apps (they die only on revocation, or after 7 days while the Google Cloud
 * consent screen is still in "Testing" status). Refreshes still run behind
 * a lock so parallel batches share one refresh.
 */
class Client
{
    public const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    public function __construct(private GoogleSheetSetting $settings)
    {
    }

    public static function make(?GoogleSheetSetting $settings = null): self
    {
        return new self($settings ?: GoogleSheetSetting::current());
    }

    /** Human-readable error out of a Google error payload. */
    public static function error(Response $response): string
    {
        $json = $response->json();
        $message = is_array($json) ? ($json['error']['message'] ?? ($json['error_description'] ?? null)) : null;

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();
    }

    /** Exchange an authorization code for the token pair. Returns the raw JSON. */
    public static function exchangeCode(GoogleSheetSetting $settings, string $code, string $redirectUri): array
    {
        $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => (string) $settings->client_id,
            'client_secret' => (string) $settings->client_secret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google token exchange failed: '.self::error($response));
        }

        return (array) $response->json();
    }

    /** Persist a token payload from a code exchange or refresh. */
    public static function storeTokens(GoogleSheetSetting $settings, array $tokens): void
    {
        $settings->access_token = (string) ($tokens['access_token'] ?? '') ?: $settings->access_token;
        // Google returns refresh_token only on the first consent — keep the old one otherwise.
        if (! empty($tokens['refresh_token'])) {
            $settings->refresh_token = (string) $tokens['refresh_token'];
        }
        $settings->access_token_expires_at = now()->addSeconds((int) ($tokens['expires_in'] ?? 3600));
        $settings->save();
    }

    // ------------------------- Sheets operations -------------------------

    public function createSpreadsheet(string $title): array
    {
        $res = $this->request('post', self::API_BASE, ['properties' => ['title' => $title]]);
        if (! $res->successful()) {
            throw new RuntimeException(self::error($res));
        }

        return (array) $res->json();
    }

    /** Spreadsheet metadata (title + tab names). */
    public function spreadsheet(string $spreadsheetId): array
    {
        $res = $this->request('get', self::API_BASE.'/'.$spreadsheetId, [
            'fields' => 'properties.title,sheets.properties.title',
        ]);
        if (! $res->successful()) {
            throw new RuntimeException(self::error($res));
        }

        return (array) $res->json();
    }

    /** Create the tab when the spreadsheet doesn't have it yet. */
    public function ensureTab(string $spreadsheetId, string $tab): void
    {
        $meta = $this->spreadsheet($spreadsheetId);
        foreach ($meta['sheets'] ?? [] as $sheet) {
            if (($sheet['properties']['title'] ?? '') === $tab) {
                return;
            }
        }

        $res = $this->request('post', self::API_BASE.'/'.$spreadsheetId.':batchUpdate', [
            'requests' => [['addSheet' => ['properties' => ['title' => $tab]]]],
        ]);
        if (! $res->successful()) {
            throw new RuntimeException(self::error($res));
        }
    }

    public function clearRange(string $spreadsheetId, string $range): void
    {
        $res = $this->request('post', self::API_BASE.'/'.$spreadsheetId.'/values/'.rawurlencode($range).':clear', (object) []);
        if (! $res->successful()) {
            throw new RuntimeException(self::error($res));
        }
    }

    /** Append rows after the last non-empty row of the range. */
    public function appendRows(string $spreadsheetId, string $range, array $rows): void
    {
        if (! $rows) {
            return;
        }

        $res = $this->request(
            'post',
            self::API_BASE.'/'.$spreadsheetId.'/values/'.rawurlencode($range).':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
            ['values' => $rows]
        );
        if (! $res->successful()) {
            throw new RuntimeException(self::error($res));
        }
    }

    // ------------------------- Plumbing -------------------------

    private function request(string $method, string $url, mixed $data = []): Response
    {
        $token = $this->freshAccessToken();

        $send = fn (string $accessToken) => Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->{$method}($url, $data);

        $response = $send($token);

        if ($response->status() === 401 && ! empty($this->settings->refresh_token)) {
            $response = $send($this->freshAccessToken(true));
        }

        return $response;
    }

    public function freshAccessToken(bool $force = false): string
    {
        $settings = $this->settings;

        if (empty($settings->refresh_token)) {
            throw new RuntimeException('Google Sheets is not connected — connect a Google account first.');
        }

        $stillValid = fn (GoogleSheetSetting $s) => ! empty($s->access_token)
            && $s->access_token_expires_at
            && now()->addSeconds(60)->lt($s->access_token_expires_at);

        if (! $force && $stillValid($settings)) {
            return $settings->access_token;
        }

        $lock = Cache::lock('google-sheets-token-refresh', 30);
        try {
            $lock->block(15);

            $settings->refresh();
            if (! $force && $stillValid($settings)) {
                return $settings->access_token;
            }

            $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $settings->refresh_token,
                'client_id' => (string) $settings->client_id,
                'client_secret' => (string) $settings->client_secret,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Google token refresh failed: '.self::error($response));
            }

            self::storeTokens($settings, (array) $response->json());

            if (empty($settings->access_token)) {
                throw new RuntimeException('Google token refresh returned no access token.');
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
