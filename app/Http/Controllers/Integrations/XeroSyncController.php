<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\XeroLog;
use App\Models\XeroSetting;
use App\Services\Xero\SyncService;
use Illuminate\Http\Request;

/**
 * Admin API for the Xero integration: settings, connection lifecycle, and
 * the cursor-batched sync endpoints driven by the frontend loop. Gated by
 * the `xero_settings` permission; routes by `tenant.feature:xero`.
 * Secrets (client_secret, tokens) are write-only.
 */
class XeroSyncController extends Controller
{
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', XeroSetting::class);

        $settings = XeroSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'connected' => $settings->isConnected(),
            'client_id' => $settings->client_id,
            'has_client_secret' => ! empty($settings->client_secret),
            'tenant_name' => $settings->tenant_name,
            'sales_account_code' => $settings->sales_account_code,
            'payment_account_code' => $settings->payment_account_code,
            'auto_sync' => (bool) $settings->auto_sync,
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
            'connect_url' => url('/xero/connect'),
            'callback_url' => url('/xero/callback'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', XeroSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'client_id' => 'nullable|string|max:191',
            'client_secret' => 'nullable|string|max:500',
            'sales_account_code' => 'nullable|string|max:20',
            'payment_account_code' => 'nullable|string|max:20',
            'auto_sync' => 'nullable|boolean',
        ]);

        $settings = XeroSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        if ($request->exists('client_id')) {
            $settings->client_id = $data['client_id'] ?: null;
        }
        // Write-only secret: only replace when the request carries a new value.
        if (! empty($data['client_secret'])) {
            $settings->client_secret = $data['client_secret'];
        }
        if ($request->exists('sales_account_code')) {
            $settings->sales_account_code = trim((string) ($data['sales_account_code'] ?? '')) ?: '200';
        }
        if ($request->exists('payment_account_code')) {
            $settings->payment_account_code = trim((string) ($data['payment_account_code'] ?? '')) ?: null;
        }
        if ($request->exists('auto_sync')) {
            $settings->auto_sync = (bool) $data['auto_sync'];
        }
        $settings->save();

        return response()->json(['success' => true]);
    }

    /** Drop the token pair and organisation; app credentials stay. */
    public function disconnect(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', XeroSetting::class);

        $settings = XeroSetting::current();
        $settings->forceFill([
            'enabled' => false,
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'tenant_id' => null,
            'tenant_name' => null,
        ])->save();

        SyncService::make($settings)->log('oauth.disconnect', 'warning', 'Organisation disconnected by admin');

        return response()->json(['success' => true]);
    }

    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', XeroSetting::class);

        $settings = XeroSetting::current();
        if (! $settings->isConnected()) {
            return response()->json(['ok' => false, 'error' => 'Not connected'], 422);
        }

        try {
            $result = SyncService::make($settings)->testConnection();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (! ($result['ok'] ?? false)) {
            SyncService::make($settings)->log('connect.test', 'error', $result['error'] ?? 'Connection test failed');
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function stats(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', XeroSetting::class);

        return response()->json(SyncService::make()->stats());
    }

    public function resetMappings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', XeroSetting::class);

        $data = $request->validate(['entity_type' => 'required|in:contact,invoice,payment']);

        $deleted = SyncService::make()->resetMappings($data['entity_type']);

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    public function syncContacts(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', XeroSetting::class);
        $service = $this->connectedService();
        $this->prepareLongRequest();

        $result = $service->pushContacts(
            (int) $request->input('start_after_id', 0),
            (int) ($request->input('batch') ?: 25)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function syncInvoices(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', XeroSetting::class);
        $service = $this->connectedService();
        $this->prepareLongRequest();

        $result = $service->pushInvoices(
            (int) $request->input('start_after_id', 0),
            (int) ($request->input('batch') ?: 10)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', XeroSetting::class);

        $query = XeroLog::query()->orderByDesc('id');
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        return response()->json($query->paginate((int) ($request->input('per_page') ?: 20)));
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', XeroSetting::class);

        XeroLog::query()->delete();

        return response()->json(['success' => true]);
    }

    private function connectedService(): SyncService
    {
        $settings = XeroSetting::current();
        abort_if(! $settings->isConnected(), 422, 'Xero is not connected — connect the organisation first.');

        return SyncService::make($settings);
    }

    private function prepareLongRequest(): void
    {
        @ini_set('max_execution_time', '600');
        @ini_set('memory_limit', '512M');
    }
}
