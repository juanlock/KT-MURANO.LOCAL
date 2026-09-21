<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\GoogleSheetSetting;
use App\Services\GoogleSheets\Client;
use App\Services\GoogleSheets\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Google Sheets OAuth â€” web routes behind auth:web, same shape as the
 * QuickBooks/Salla/Xero connect/callback pairs.
 */
class GoogleSheetsOAuthController extends Controller
{
    private const SETTINGS_PAGE = '/next/integrations/google-sheets';

    /** GET /google-sheets/connect â€” redirect the admin to Google's consent screen. */
    public function connect(Request $request)
    {
        $this->authorizeForUser($request->user(), 'update', GoogleSheetSetting::class);

        $settings = GoogleSheetSetting::current();
        if (! $settings->hasApiCredentials()) {
            return redirect(self::SETTINGS_PAGE.'?gs_error='.urlencode('Save the OAuth Client ID and Client Secret first.'));
        }

        $state = Str::random(40);
        Cache::put("gsheets_state_{$state}", ['user_id' => Auth::id()], now()->addMinutes(10));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => (string) $settings->client_id,
            'redirect_uri' => url('/google-sheets/callback'),
            'scope' => Client::SCOPE,
            // offline + forced consent so Google issues a refresh token even
            // on repeat connections.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return redirect()->away(Client::AUTH_URL.'?'.$query);
    }

    /** GET /google-sheets/callback â€” exchange the code and store the token pair. */
    public function callback(Request $request)
    {
        $error = (string) $request->query('error', '');
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($error !== '' || $code === '') {
            return redirect(self::SETTINGS_PAGE.'?gs_error='.urlencode($error ?: 'Google returned no authorization code.'));
        }

        // Same posture as the QuickBooks callback: a missing state is logged
        // but not fatal â€” the exchange still requires our client credentials.
        $stash = Cache::pull("gsheets_state_{$state}");
        if (! $stash) {
            \Log::warning('Google Sheets OAuth callback: state missing/expired (continuing)', ['state' => $state]);
        }

        $settings = GoogleSheetSetting::current();

        try {
            $tokens = Client::exchangeCode($settings, $code, url('/google-sheets/callback'));
            Client::storeTokens($settings, $tokens);
            if (empty($settings->refresh_token)) {
                throw new \RuntimeException('Google issued no refresh token â€” remove the app from your Google account permissions and connect again.');
            }
            $settings->enabled = true;
            $settings->save();

            ExportService::make($settings)->log('oauth.connect', 'info', 'Google account connected');

            return redirect(self::SETTINGS_PAGE.'?connected=1');
        } catch (\Throwable $e) {
            \Log::warning('Google Sheets OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect(self::SETTINGS_PAGE.'?gs_error='.urlencode($e->getMessage()));
        }
    }
}
