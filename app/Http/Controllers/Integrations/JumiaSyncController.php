<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\JumiaLog;
use App\Models\JumiaSetting;
use App\Models\Warehouse;
use App\Services\Jumia\SyncService;
use Illuminate\Http\Request;

/**
 * Admin API for the Jumia integration: settings and the cursor-batched sync
 * endpoints driven by the frontend loop. Gated by the `jumia_settings`
 * permission; routes by `tenant.feature:jumia`. The API key is write-only.
 */
class JumiaSyncController extends Controller
{
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', JumiaSetting::class);

        $settings = JumiaSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'configured' => $settings->isConfigured(),
            'api_url' => $settings->api_url,
            'user_email' => $settings->user_email,
            'has_api_key' => ! empty($settings->api_key),
            'warehouse_id' => $settings->warehouse_id,
            'warehouses' => Warehouse::whereNull('deleted_at')->get(['id', 'name']),
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', JumiaSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'api_url' => 'nullable|string|max:191|url',
            'user_email' => 'nullable|string|max:191|email',
            'api_key' => 'nullable|string|max:191',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $settings = JumiaSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        if ($request->exists('api_url')) {
            $settings->api_url = $data['api_url'] ? rtrim($data['api_url'], '/') : null;
        }
        if ($request->exists('user_email')) {
            $settings->user_email = $data['user_email'] ?: null;
        }
        // Write-only secret: only replace when the request carries a new value.
        if (! empty($data['api_key'])) {
            $settings->api_key = trim($data['api_key']);
        }
        if ($request->exists('warehouse_id')) {
            $settings->warehouse_id = $data['warehouse_id'] ?: null;
        }
        $settings->save();

        return response()->json(['success' => true, 'configured' => $settings->isConfigured()]);
    }

    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', JumiaSetting::class);

        $settings = JumiaSetting::current();
        if (! $settings->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Add the API URL, email and key first.'], 422);
        }

        $result = SyncService::make($settings)->testConnection();
        if (! ($result['ok'] ?? false)) {
            SyncService::make($settings)->log('connect.test', 'error', $result['error'] ?? 'Connection test failed');
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function stats(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', JumiaSetting::class);

        return response()->json(SyncService::make()->stats());
    }

    public function syncPriceStock(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', JumiaSetting::class);
        $service = $this->configuredService();
        $this->prepareLongRequest();

        $result = $service->pushPriceStock(
            (int) $request->input('start_after_id', 0),
            (int) ($request->input('batch') ?: 100)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function syncOrders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', JumiaSetting::class);
        $service = $this->configuredService();
        $this->prepareLongRequest();

        $result = $service->pullOrders(
            (int) ($request->input('page_info') ?: 1),
            (int) ($request->input('per_page') ?: 20),
            (int) $request->user('api')->id,
            $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', JumiaSetting::class);

        $query = JumiaLog::query()->orderByDesc('id');
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        return response()->json($query->paginate((int) ($request->input('per_page') ?: 20)));
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', JumiaSetting::class);

        JumiaLog::query()->delete();

        return response()->json(['success' => true]);
    }

    private function configuredService(): SyncService
    {
        $settings = JumiaSetting::current();
        abort_if(! $settings->isConfigured(), 422, 'Jumia is not configured — add the API URL, email and key first.');

        return SyncService::make($settings);
    }

    private function prepareLongRequest(): void
    {
        @ini_set('max_execution_time', '600');
        @ini_set('memory_limit', '512M');
    }
}
