<?php

namespace App\Services;

use App\Models\MobileDeviceToken;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Push notifications for the mobile admin app via FCM HTTP v1.
 *
 * Auth is a plain service-account flow (RS256 assertion exchanged for an
 * OAuth2 access token) so no Google SDK dependency is needed — the app
 * already ships firebase/php-jwt. The whole service is a silent no-op
 * until a service-account JSON exists at config('mobile.fcm_credentials'),
 * and every send is best-effort: push failures must never break the
 * request that triggered them.
 */
class FcmService
{
    private const TOKEN_CACHE_KEY = 'fcm_v1_access_token';

    /** Whether pushes are configured on this server. */
    public function enabled(): bool
    {
        $path = config('mobile.fcm_credentials');

        return $path && is_file($path);
    }

    /**
     * Push to every registered device of the given users.
     * $data values are cast to string (FCM v1 requires string-only data).
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        if (! $this->enabled() || ! $userIds) {
            return;
        }

        $tokens = MobileDeviceToken::whereIn('user_id', $userIds)
            ->pluck('token')
            ->all();

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        if (! $this->enabled() || ! $tokens) {
            return;
        }

        $credentials = $this->credentials();
        $accessToken = $this->accessToken($credentials);
        if (! $credentials || ! $accessToken) {
            return;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/'.$credentials['project_id'].'/messages:send';
        $stringData = array_map(fn ($v) => (string) $v, $data);

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($accessToken)->post($url, [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $stringData,
                    ],
                ]);

                // Stale registration: Firebase rotated or the app was
                // uninstalled — drop the row so we stop retrying it.
                if (in_array($response->status(), [404, 410], true)) {
                    MobileDeviceToken::where('token', $token)->delete();
                } elseif ($response->failed()) {
                    Log::warning('FCM push failed ('.$response->status().'): '.$response->body());
                }
            } catch (\Throwable $e) {
                Log::warning('FCM push failed: '.$e->getMessage());
            }
        }
    }

    private function credentials(): ?array
    {
        try {
            $json = json_decode((string) file_get_contents(config('mobile.fcm_credentials')), true);
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_array($json) || empty($json['project_id']) || empty($json['client_email']) || empty($json['private_key'])) {
            Log::warning('FCM credentials file is missing project_id / client_email / private_key.');

            return null;
        }

        return $json;
    }

    /** Google OAuth2 access token for the FCM scope, cached below its 1h expiry. */
    private function accessToken(?array $credentials): ?string
    {
        if (! $credentials) {
            return null;
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(50), function () use ($credentials) {
            $now = time();
            $assertion = JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if ($response->failed()) {
                Log::warning('FCM OAuth token exchange failed ('.$response->status().'): '.$response->body());

                return null;
            }

            return $response->json('access_token');
        });
    }
}
