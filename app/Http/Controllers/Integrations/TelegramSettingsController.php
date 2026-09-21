<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\TelegramSetting;
use App\Services\Notifications\ChannelNotifier;
use App\Services\Webhooks\WebhookService;
use Illuminate\Http\Request;

/**
 * Telegram integration settings: bot token + chat id, enable switch,
 * subscribed events, and a test send. Gated by the `telegram_settings`
 * permission; routes by `tenant.feature:telegram`. The bot token is
 * write-only: reads expose a recognisable tail only.
 */
class TelegramSettingsController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', TelegramSetting::class);

        $settings = TelegramSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'configured' => $settings->isConfigured(),
            'bot_token_hint' => $settings->bot_token ? '…' . substr($settings->bot_token, -4) : null,
            'chat_id' => $settings->chat_id,
            'events' => $settings->events ?: [],
            'available_events' => WebhookService::availableEvents(),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', TelegramSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'bot_token' => 'nullable|string|max:191',
            'chat_id' => 'nullable|string|max:64',
            'events' => 'nullable|array',
            'events.*' => 'string',
        ]);

        $settings = TelegramSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        // Write-only secret: only replace when the request carries the field.
        if ($request->exists('bot_token')) {
            $settings->bot_token = $data['bot_token'] ?: null;
        }
        if ($request->exists('chat_id')) {
            $settings->chat_id = $data['chat_id'] ?: null;
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
        $this->authorizeForUser($request->user('api'), 'update', TelegramSetting::class);

        if (! TelegramSetting::current()->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Add the bot token and chat id first.'], 422);
        }

        $result = $notifier->send(
            'telegram',
            "\u{2705} Test notification from Stocky — your Telegram integration works."
        );

        return response()->json(['ok' => $result['success'], 'error' => $result['error']], $result['success'] ? 200 : 422);
    }
}
