<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\PrestashopLog;
use App\Models\PrestashopSetting;
use App\Models\Warehouse;
use App\Services\Prestashop\SyncService;
use Illuminate\Http\Request;

/**
 * Admin API for the PrestaShop integration: settings and the cursor-batched
 * sync endpoints driven by the frontend loop. Gated by the
 * `prestashop_settings` permission; routes by `tenant.feature:prestashop`.
 * The webservice key is write-only.
 */
class PrestashopSyncController extends Controller
{
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PrestashopSetting::class);

        $settings = PrestashopSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'configured' => $settings->isConfigured(),
            'store_url' => $settings->store_url,
            'has_api_key' => ! empty($settings->api_key),
            'default_language_id' => (int) $settings->default_language_id,
            'warehouse_id' => $settings->warehouse_id,
            'warehouses' => Warehouse::whereNull('deleted_at')->get(['id', 'name']),
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrestashopSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'store_url' => 'nullable|string|max:191|url',
            'api_key' => 'nullable|string|max:191',
            'default_language_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $settings = PrestashopSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        if ($request->exists('store_url')) {
            $settings->store_url = $data['store_url'] ? rtrim($data['store_url'], '/') : null;
        }
        // Write-only secret: only replace when the request carries a new value.
        if (! empty($data['api_key'])) {
            $settings->api_key = $data['api_key'];
        }
        if ($request->exists('default_language_id')) {
            $settings->default_language_id = (int) ($data['default_language_id'] ?: 1);
        }
        if ($request->exists('warehouse_id')) {
            $settings->warehouse_id = $data['warehouse_id'] ?: null;
        }
        $settings->save();

        return response()->json(['success' => true, 'configured' => $settings->isConfigured()]);
    }

    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PrestashopSetting::class);

        $settings = PrestashopSetting::current();
        if (! $settings->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Add the store URL and webservice key first.'], 422);
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
        $this->authorizeForUser($request->user('api'), 'view', PrestashopSetting::class);

        return response()->json(SyncService::make()->stats());
    }

    public function resetMappings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrestashopSetting::class);

        $data = $request->validate(['entity_type' => 'nullable|in:product,customer,order']);

        $deleted = SyncService::make()->resetMappings($data['entity_type'] ?? null);

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    public function syncProducts(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrestashopSetting::class);
        $service = $this->configuredService();
        $this->prepareLongRequest();

        if ($request->query('mode', 'push') === 'pull') {
            $result = $service->pullProducts((int) ($request->input('page_info') ?: 1), (int) ($request->input('per_page') ?: 50));
        } else {
            $result = $service->pushProducts(
                (int) $request->input('start_after_id', 0),
                (int) ($request->input('batch') ?: 15)
            );
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function syncInventory(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrestashopSetting::class);
        $service = $this->configuredService();
        $this->prepareLongRequest();

        $result = $service->pushInventory(
            (int) $request->input('start_after_id', 0),
            (int) ($request->input('batch') ?: 25)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function syncOrders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrestashopSetting::class);
        $service = $this->configuredService();
        $this->prepareLongRequest();

        $result = $service->pullOrders(
            (int) ($request->input('page_info') ?: 1),
            (int) ($request->input('per_page') ?: 25),
            (int) $request->user('api')->id,
            $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PrestashopSetting::class);

        $query = PrestashopLog::query()->orderByDesc('id');
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        return response()->json($query->paginate((int) ($request->input('per_page') ?: 20)));
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrestashopSetting::class);

        PrestashopLog::query()->delete();

        return response()->json(['success' => true]);
    }

    private function configuredService(): SyncService
    {
        $settings = PrestashopSetting::current();
        abort_if(! $settings->isConfigured(), 422, 'PrestaShop is not configured — add the store URL and webservice key first.');

        return SyncService::make($settings);
    }

    private function prepareLongRequest(): void
    {
        @ini_set('max_execution_time', '600');
        @ini_set('memory_limit', '512M');
    }
}
