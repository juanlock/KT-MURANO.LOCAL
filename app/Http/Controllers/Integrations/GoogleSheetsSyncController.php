<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\GoogleSheetLog;
use App\Models\GoogleSheetSetting;
use App\Services\GoogleSheets\ExportService;
use Illuminate\Http\Request;

/**
 * Admin API for the Google Sheets integration: settings, connection
 * lifecycle, spreadsheet management and the batch export endpoints.
 * Gated by the `google_sheets_settings` permission; routes by
 * `tenant.feature:google_sheets`. The client secret is write-only.
 */
class GoogleSheetsSyncController extends Controller
{
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', GoogleSheetSetting::class);

        $settings = GoogleSheetSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'connected' => $settings->isConnected(),
            'client_id' => $settings->client_id,
            'has_client_secret' => ! empty($settings->client_secret),
            'spreadsheet_id' => $settings->spreadsheet_id,
            'spreadsheet_url' => $settings->spreadsheetUrl(),
            'auto_export' => (bool) $settings->auto_export,
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
            'connect_url' => url('/google-sheets/connect'),
            'callback_url' => url('/google-sheets/callback'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', GoogleSheetSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'client_id' => 'nullable|string|max:191',
            'client_secret' => 'nullable|string|max:500',
            'spreadsheet' => 'nullable|string|max:255',
            'auto_export' => 'nullable|boolean',
        ]);

        $settings = GoogleSheetSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        if ($request->exists('client_id')) {
            $settings->client_id = $data['client_id'] ?: null;
        }
        // Write-only secret: only replace when the request carries a new value.
        if (! empty($data['client_secret'])) {
            $settings->client_secret = $data['client_secret'];
        }
        if ($request->exists('spreadsheet')) {
            $settings->spreadsheet_id = $this->parseSpreadsheetId((string) ($data['spreadsheet'] ?? ''));
        }
        if ($request->exists('auto_export')) {
            $settings->auto_export = (bool) $data['auto_export'];
        }
        $settings->save();

        return response()->json(['success' => true, 'spreadsheet_id' => $settings->spreadsheet_id]);
    }

    /** Accept a raw spreadsheet id or a full docs.google.com URL. */
    private function parseSpreadsheetId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $value, $m)) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9_-]{20,}$/', $value) ? $value : null;
    }

    /** Drop the token pair; app credentials and spreadsheet stay. */
    public function disconnect(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', GoogleSheetSetting::class);

        $settings = GoogleSheetSetting::current();
        $settings->forceFill([
            'enabled' => false,
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
        ])->save();

        ExportService::make($settings)->log('oauth.disconnect', 'warning', 'Google account disconnected by admin');

        return response()->json(['success' => true]);
    }

    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', GoogleSheetSetting::class);

        $settings = GoogleSheetSetting::current();
        if (! $settings->isConnected()) {
            return response()->json(['ok' => false, 'error' => 'Not connected'], 422);
        }

        $result = ExportService::make($settings)->testConnection();
        if (! ($result['ok'] ?? false)) {
            ExportService::make($settings)->log('connect.test', 'error', $result['error'] ?? 'Connection test failed');
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function createSpreadsheet(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', GoogleSheetSetting::class);
        $service = $this->connectedService();

        try {
            return response()->json($service->createSpreadsheet());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function stats(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', GoogleSheetSetting::class);

        return response()->json(ExportService::make()->stats());
    }

    public function exportSales(Request $request)
    {
        return $this->runExport($request, 'exportSales');
    }

    public function exportProducts(Request $request)
    {
        return $this->runExport($request, 'exportProducts');
    }

    public function exportCustomers(Request $request)
    {
        return $this->runExport($request, 'exportCustomers');
    }

    private function runExport(Request $request, string $method)
    {
        $this->authorizeForUser($request->user('api'), 'update', GoogleSheetSetting::class);
        $service = $this->connectedService();
        @ini_set('max_execution_time', '600');
        @ini_set('memory_limit', '512M');

        $result = $service->{$method}(
            (int) $request->input('start_after_id', 0),
            (int) ($request->input('batch') ?: 200)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', GoogleSheetSetting::class);

        $query = GoogleSheetLog::query()->orderByDesc('id');
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        return response()->json($query->paginate((int) ($request->input('per_page') ?: 20)));
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', GoogleSheetSetting::class);

        GoogleSheetLog::query()->delete();

        return response()->json(['success' => true]);
    }

    private function connectedService(): ExportService
    {
        $settings = GoogleSheetSetting::current();
        abort_if(! $settings->isConnected(), 422, 'Google Sheets is not connected — connect a Google account first.');

        return ExportService::make($settings);
    }
}
