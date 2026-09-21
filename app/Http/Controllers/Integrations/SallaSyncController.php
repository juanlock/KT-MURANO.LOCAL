<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\SallaLog;
use App\Models\SallaSetting;
use App\Models\Warehouse;
use App\Services\Salla\SyncService;
use Illuminate\Http\Request;

/**
 * Admin API for the Salla integration: settings, connection lifecycle, and
 * the cursor-batched sync endpoints driven by the frontend loop. Gated by
 * the `salla_settings` permission; routes by `tenant.feature:salla`.
 * Secrets (client_secret, webhook_secret, tokens) are write-only.
 */
class SallaSyncController extends Controller
{
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SallaSetting::class);

        $settings = SallaSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'connected' => $settings->isConnected(),
            'client_id' => $settings->client_id,
            'has_client_secret' => ! empty($settings->client_secret),
            'has_webhook_secret' => ! empty($settings->webhook_secret),
            'store' => [
                'id' => $settings->store_id,
                'name' => $settings->store_name,
                'domain' => $settings->store_domain,
            ],
            'warehouse_id' => $settings->warehouse_id,
            'warehouses' => Warehouse::whereNull('deleted_at')->get(['id', 'name']),
            'access_token_expires_at' => optional($settings->access_token_expires_at)->toIso8601String(),
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
            'connect_url' => url('/salla/connect'),
            'callback_url' => url('/salla/callback'),
            'webhook_url' => url('/api/salla/webhook'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', SallaSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'client_id' => 'nullable|string|max:191',
            'client_secret' => 'nullable|string|max:500',
            'webhook_secret' => 'nullable|string|max:500',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $settings = SallaSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        if ($request->exists('client_id')) {
            $settings->client_id = $data['client_id'] ?: null;
        }
        // Write-only secrets: only replace when the request carries the field
        // (the page sends them only when the admin typed a new value).
        if (! empty($data['client_secret'])) {
            $settings->client_secret = $data['client_secret'];
        }
        if (! empty($data['webhook_secret'])) {
            $settings->webhook_secret = $data['webhook_secret'];
        }
        if ($request->exists('warehouse_id')) {
            $settings->warehouse_id = $data['warehouse_id'] ?: null;
        }
        $settings->save();

        return response()->json(['success' => true]);
    }

    /** Drop the token pair and store metadata; app credentials stay. */
    public function disconnect(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', SallaSetting::class);

        $settings = SallaSetting::current();
        $settings->forceFill([
            'enabled' => false,
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
            'store_id' => null,
            'store_name' => null,
            'store_domain' => null,
        ])->save();

        SyncService::make($settings)->log('oauth.disconnect', 'warning', 'Store disconnected by admin');

        return response()->json(['success' => true]);
    }

    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SallaSetting::class);

        $settings = SallaSetting::current();
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
        $this->authorizeForUser($request->user('api'), 'view', SallaSetting::class);

        return response()->json(SyncService::make()->stats());
    }

    public function resetMappings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', SallaSetting::class);

        $data = $request->validate(['entity_type' => 'nullable|in:product,variant,customer,order']);
        $service = SyncService::make();

        $deleted = $service->resetMappings($data['entity_type'] ?? null);
        if (($data['entity_type'] ?? null) === 'product') {
            $deleted += $service->resetMappings('variant');
        }

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    public function syncProducts(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', SallaSetting::class);
        $service = $this->connectedService();
        $this->prepareLongRequest();

        if ($request->query('mode', 'push') === 'pull') {
            $result = $service->pullProducts((int) ($request->input('page_info') ?: 1), (int) ($request->input('per_page') ?: 30));
        } else {
            $result = $service->pushProducts(
                (bool) $request->boolean('only_unsynced'),
                (int) $request->input('start_after_id', 0),
                (int) ($request->input('batch') ?: 15)
            );
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function syncInventory(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', SallaSetting::class);
        $service = $this->connectedService();
        $this->prepareLongRequest();

        $result = $service->pushInventory(
            (int) $request->input('start_after_id', 0),
            (int) ($request->input('batch') ?: 30)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function syncOrders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', SallaSetting::class);
        $service = $this->connectedService();
        $this->prepareLongRequest();

        $result = $service->pullOrders(
            (int) ($request->input('page_info') ?: 1),
            (int) ($request->input('per_page') ?: 15),
            (int) $request->user('api')->id,
            $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SallaSetting::class);

        $query = SallaLog::query()->orderByDesc('id');
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        return response()->json($query->paginate((int) ($request->input('per_page') ?: 20)));
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', SallaSetting::class);

        SallaLog::query()->delete();

        return response()->json(['success' => true]);
    }

    private function connectedService(): SyncService
    {
        $settings = SallaSetting::current();
        abort_if(! $settings->isConnected(), 422, 'Salla is not connected — connect the store first.');

        return SyncService::make($settings);
    }

    private function prepareLongRequest(): void
    {
        @ini_set('max_execution_time', '600');
        @ini_set('memory_limit', '512M');
    }
}
