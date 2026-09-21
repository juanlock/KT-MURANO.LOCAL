<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendChannelNotificationJob;
use App\Models\SlackSetting;
use App\Models\TelegramSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fans canonical webhook events out to chat notification channels
 * (Slack incoming webhooks, Telegram bots). Invoked from
 * WebhookDispatcher::dispatch so every event source (model listeners,
 * low-stock checks, future emitters) is covered by one hook point.
 * Mirrors the webhook module's guarantee: never breaks business flow.
 */
class ChannelNotifier
{
    public const CHANNELS = ['slack', 'telegram'];

    public function dispatch(string $event, array $payload): void
    {
        try {
            $text = null; // formatted lazily, only when a channel wants the event

            foreach (self::CHANNELS as $channel) {
                $settings = $this->settings($channel);
                if (
                    ! $settings
                    || ! $settings->enabled
                    || ! $settings->isConfigured()
                    || ! $settings->subscribesTo($event)
                ) {
                    continue;
                }

                $text = $text ?? ChannelMessage::format($event, $payload);
                SendChannelNotificationJob::dispatch($channel, $text)->onQueue('webhooks');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Read-only settings lookup — deliberately NOT ::current(), so the hot
     * dispatch path never inserts rows into tenants that never opened the page.
     */
    public function settings(string $channel): SlackSetting|TelegramSetting|null
    {
        return $channel === 'slack'
            ? SlackSetting::query()->first()
            : TelegramSetting::query()->first();
    }

    /**
     * Synchronous send, shared by the queued job and the settings pages' Test
     * button. Returns ['success' => bool, 'error' => ?string]; never throws.
     */
    public function send(string $channel, string $text): array
    {
        try {
            $settings = $this->settings($channel);
            if (! $settings || ! $settings->isConfigured()) {
                return ['success' => false, 'error' => 'Channel is not configured.'];
            }

            $response = $channel === 'slack'
                ? Http::timeout(15)->post($settings->webhook_url, ['text' => $text])
                : Http::timeout(15)->post(
                    'https://api.telegram.org/bot' . $settings->bot_token . '/sendMessage',
                    ['chat_id' => $settings->chat_id, 'text' => $text]
                );

            if ($response->successful()) {
                return ['success' => true, 'error' => null];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . Str::limit((string) $response->body(), 200),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
