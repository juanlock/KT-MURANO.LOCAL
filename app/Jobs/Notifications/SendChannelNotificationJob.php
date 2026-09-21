<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\ChannelNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Delivers one formatted chat notification (Slack / Telegram). Settings are
 * re-read at run time, so a channel disabled after queueing sends nothing.
 */
class SendChannelNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Job timeout — must be higher than the per-request HTTP timeout (15s). */
    public int $timeout = 30;

    public function __construct(public string $channel, public string $text)
    {
    }

    /** Backoff schedule (seconds): 1m, 5m. */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ChannelNotifier $notifier): void
    {
        $settings = $notifier->settings($this->channel);
        if (! $settings || ! $settings->enabled || ! $settings->isConfigured()) {
            return;
        }

        $result = $notifier->send($this->channel, $this->text);
        if (! $result['success']) {
            // Throw so the queue retries per backoff(); the final failure
            // lands in failed_jobs like any other delivery problem.
            throw new RuntimeException(ucfirst($this->channel) . ' notification failed: ' . $result['error']);
        }
    }
}
