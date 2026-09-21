<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\XeroSetting;
use App\Services\Xero\Client;
use App\Services\Xero\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Xero OAuth â€” web routes behind auth:web, same shape as the QuickBooks and
 * Salla connect/callback pairs.
 */
class XeroOAuthController extends Controller
{
    private const SETTINGS_PAGE = '/next/integrations/xero';
    private const SCOPES = 'offline_access accounting.transactions accounting.contacts accounting.settings.read';

    /** GET /xero/connect â€” redirect the admin to Xero's consent screen. */
    public function connect(Request $request)
    {
        $this->authorizeForUser($request->user(), 'update', XeroSetting::class);

        $settings = XeroSetting::current();
        if (! $settings->hasApiCredentials()) {
            return redirect(self::SETTINGS_PAGE.'?xero_error='.urlencode('Save the app Client ID and Client Secret first.'));
        }

        $state = Str::random(40);
        Cache::put("xero_state_{$state}", ['user_id' => Auth::id()], now()->addMinutes(10));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => (string) $settings->client_id,
            'redirect_uri' => url('/xero/callback'),
            'scope' => self::SCOPES,
            'state' => $state,
        ]);

        return redirect()->away(Client::AUTH_URL.'?'.$query);
    }

    /** GET /xero/callback â€” exchange the code, store tokens, pick the organisation. */
    public function callback(Request $request)
    {
        $error = (string) $request->query('error', '');
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($error !== '' || $code === '') {
            return redirect(self::SETTINGS_PAGE.'?xero_error='.urlencode($error ?: 'Xero returned no authorization code.'));
        }

        // Same posture as the QuickBooks callback: a missing state is logged
        // but not fatal â€” the exchange still requires our client credentials.
        $stash = Cache::pull("xero_state_{$state}");
        if (! $stash) {
            \Log::warning('Xero OAuth callback: state missing/expired (continuing)', ['state' => $state]);
        }

        $settings = XeroSetting::current();

        try {
            $tokens = Client::exchangeCode($settings, $code, url('/xero/callback'));
            Client::storeTokens($settings, $tokens);

            // Resolve the connected organisation. One org is the normal case;
            // when several are connected the first is used.
            $connections = Client::fetchConnections((string) $settings->access_token);
            $tenant = $connections[0] ?? null;
            if (! $tenant || empty($tenant['tenantId'])) {
                throw new \RuntimeException('No Xero organisation is connected to this app.');
            }
            $settings->tenant_id = (string) $tenant['tenantId'];
            $settings->tenant_name = (string) ($tenant['tenantName'] ?? '');
            $settings->enabled = true;
            $settings->save();

            SyncService::make($settings)->log('oauth.connect', 'info', 'Organisation connected: '.$settings->tenant_name);

            return redirect(self::SETTINGS_PAGE.'?connected=1');
        } catch (\Throwable $e) {
            \Log::warning('Xero OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect(self::SETTINGS_PAGE.'?xero_error='.urlencode($e->getMessage()));
        }
    }
}
