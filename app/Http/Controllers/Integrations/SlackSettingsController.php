<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\SlackSetting;
use App\Services\Notifications\ChannelNotifier;
use App\Services\Webhooks\WebhookService;
use Illuminate\Http\Request;

/**
 * Slack integration settings (Settings-style page): incoming-webhook URL,
 * enable switch, subscribed events, and a test send. Gated by the
 * `slack_settings` permission; routes by `tenant.feature:slack`.
 * The webhook URL is write-only: reads expose a recognisable tail only.
 */
class SlackSettingsController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SlackSetting::class);

        $settings = SlackSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'configured' => $settings->isConfigured(),
            'webhook_hint' => $settings->webhook_url ? '…' . substr($settings->webhook_url, -6) : null,
            'events' => $settings->events ?: [],
            'available_events' => WebhookService::availableEvents(),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', SlackSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'webhook_url' => 'nullable|string|max:500|starts_with:https://hooks.slack.com/',
            'events' => 'nullable|array',
            'events.*' => 'string',
        ]);

        $settings = SlackSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        // Write-only secret: only replace when the request carries the field
        // (the page sends it only when the admin typed a new URL).
        if ($request->exists('webhook_url')) {
            $settings->webhook_url = $data['webhook_url'] ?: null;
        }
        if ($request->exists('events')) {
            $settings->events = array_values(array_intersect(
                $data['events'] ?? [],
                WebhookService::availableEvents()
            ));
        }
        $settings->save();

        return response()->json(['success' => true, 'configured' => $settings->isConfigured()]);
    }

    public function test(Request $request, ChannelNotifier $notifier)
    {
        $this->authorizeForUser($request->user('api'), 'update', SlackSetting::class);

        if (! SlackSetting::current()->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Add a webhook URL first.'], 422);
        }

        $result = $notifier->send(
            'slack',
            "\u{2705} Test notification from Stocky — your Slack integration works."
        );

        return response()->json(['ok' => $result['success'], 'error' => $result['error']], $result['success'] ? 200 : 422);
    }
}
