<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\MailchimpLog;
use App\Models\MailchimpSetting;
use App\Services\Mailchimp\MailchimpService;
use Illuminate\Http\Request;

/**
 * Admin API for the Mailchimp integration: settings, audience picker and the
 * batch customer push. Gated by the `mailchimp_settings` permission; routes
 * by `tenant.feature:mailchimp`. The API key is write-only.
 */
class MailchimpSettingsController extends Controller
{
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MailchimpSetting::class);

        $settings = MailchimpSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'configured' => $settings->isConfigured(),
            'ready' => $settings->isReady(),
            'has_api_key' => ! empty($settings->api_key),
            'list_id' => $settings->list_id,
            'list_name' => $settings->list_name,
            'double_opt_in' => (bool) $settings->double_opt_in,
            'auto_sync' => (bool) $settings->auto_sync,
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', MailchimpSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'api_key' => 'nullable|string|max:191',
            'list_id' => 'nullable|string|max:64',
            'list_name' => 'nullable|string|max:191',
            'double_opt_in' => 'nullable|boolean',
            'auto_sync' => 'nullable|boolean',
        ]);

        $settings = MailchimpSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        // Write-only secret: only replace when the request carries a new value.
        if (! empty($data['api_key'])) {
            $settings->api_key = trim($data['api_key']);
        }
        if ($request->exists('list_id')) {
            $settings->list_id = $data['list_id'] ?: null;
            $settings->list_name = $data['list_name'] ?? null;
        }
        if ($request->exists('double_opt_in')) {
            $settings->double_opt_in = (bool) $data['double_opt_in'];
        }
        if ($request->exists('auto_sync')) {
            $settings->auto_sync = (bool) $data['auto_sync'];
        }
        $settings->save();

        return response()->json(['success' => true, 'ready' => $settings->isReady()]);
    }

    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MailchimpSetting::class);

        $settings = MailchimpSetting::current();
        if (! $settings->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Add the API key first.'], 422);
        }

        try {
            $result = MailchimpService::make($settings)->testConnection();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (! ($result['ok'] ?? false)) {
            MailchimpService::make($settings)->log('connect.test', 'error', $result['error'] ?? 'Connection test failed');
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /** The account's audiences, for the settings page picker. */
    public function lists(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MailchimpSetting::class);

        $settings = MailchimpSetting::current();
        abort_if(! $settings->isConfigured(), 422, 'Add the API key first.');

        try {
            return response()->json(['lists' => MailchimpService::make($settings)->lists()]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function stats(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MailchimpSetting::class);

        return response()->json(MailchimpService::make()->stats());
    }

    public function syncCustomers(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', MailchimpSetting::class);

        $settings = MailchimpSetting::current();
        abort_if(! $settings->isReady(), 422, 'Add the API key and pick an audience first.');
        @ini_set('max_execution_time', '600');

        $result = MailchimpService::make($settings)->pushCustomers(
            (int) $request->input('start_after_id', 0),
            (int) ($request->input('batch') ?: 50)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MailchimpSetting::class);

        $query = MailchimpLog::query()->orderByDesc('id');
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        return response()->json($query->paginate((int) ($request->input('per_page') ?: 20)));
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', MailchimpSetting::class);

        MailchimpLog::query()->delete();

        return response()->json(['success' => true]);
    }
}
