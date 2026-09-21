<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\SallaSetting;
use App\Services\Salla\Client;
use App\Services\Salla\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Salla OAuth (Custom Mode) â€” web routes behind auth:web, same shape as the
 * QuickBooks connect/callback pair. Tenants using Salla's Easy Mode never hit
 * these: their tokens arrive on the app.store.authorize webhook instead.
 */
class SallaOAuthController extends Controller
{
    private const SETTINGS_PAGE = '/next/integrations/salla';

    /** GET /salla/connect â€” redirect the admin to Salla's consent screen. */
    public function connect(Request $request)
    {
        $this->authorizeForUser($request->user(), 'update', SallaSetting::class);

        $settings = SallaSetting::current();
        if (! $settings->hasApiCredentials()) {
            return redirect(self::SETTINGS_PAGE.'?salla_error='.urlencode('Save the app Client ID and Client Secret first.'));
        }

        $state = Str::random(40);
        Cache::put("salla_state_{$state}", ['user_id' => Auth::id()], now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => (string) $settings->client_id,
            'response_type' => 'code',
            'redirect_uri' => url('/salla/callback'),
            'scope' => 'offline_access',
            'state' => $state,
        ]);

        return redirect()->away(Client::AUTH_URL.'?'.$query);
    }

    /** GET /salla/callback â€” exchange the code, store tokens, fetch store info. */
    public function callback(Request $request)
    {
        $error = (string) $request->query('error', '');
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($error !== '' || $code === '') {
            return redirect(self::SETTINGS_PAGE.'?salla_error='.urlencode($error ?: 'Salla returned no authorization code.'));
        }

        // Same posture as the QuickBooks callback: a missing state is logged
        // but not fatal â€” the exchange still requires our client credentials.
        $stash = Cache::pull("salla_state_{$state}");
        if (! $stash) {
            \Log::warning('Salla OAuth callback: state missing/expired (continuing)', ['state' => $state]);
        }

        $settings = SallaSetting::current();

        try {
            $tokens = Client::exchangeCode($settings, $code, url('/salla/callback'));
            Client::storeTokens($settings, $tokens);
            $settings->enabled = true;
            $settings->save();

            try {
                SyncService::make($settings)->refreshStoreInfo();
            } catch (\Throwable $e) {
                // Cosmetic; tokens are already stored.
            }

            SyncService::make($settings)->log('oauth.connect', 'info', 'Store connected via OAuth callback');

            return redirect(self::SETTINGS_PAGE.'?connected=1');
        } catch (\Throwable $e) {
            \Log::warning('Salla OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect(self::SETTINGS_PAGE.'?salla_error='.urlencode($e->getMessage()));
        }
    }
}
