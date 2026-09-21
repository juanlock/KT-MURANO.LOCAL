<?php

namespace App\Jobs\Mailchimp;

use App\Models\Client as Customer;
use App\Models\MailchimpSetting;
use App\Services\Mailchimp\MailchimpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Auto-sync: upserts one customer into the Mailchimp audience. Settings are
 * re-read at run time, so toggling auto-sync off cancels queued work.
 */
class MailchimpUpsertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $clientId)
    {
    }

    /** Backoff schedule (seconds): 1m, 5m. */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $settings = MailchimpSetting::query()->first();
        if (! $settings || ! $settings->enabled || ! $settings->auto_sync || ! $settings->isReady()) {
            return;
        }

        $customer = Customer::whereNull('deleted_at')->find($this->clientId);
        if (! $customer || trim((string) $customer->email) === '') {
            return; // nothing to push — not an error
        }

        $service = MailchimpService::make($settings);
        try {
            $service->upsertMember($customer);
            $service->log('auto_sync.upsert', 'info', 'Customer auto-synced: '.$customer->email, ['client_id' => $customer->id]);
        } catch (RuntimeException $e) {
            $service->log('auto_sync.upsert', 'warning', 'Auto-sync failed for '.$customer->email.': '.$e->getMessage(), ['client_id' => $customer->id]);
            throw $e; // retry per backoff()
        }
    }
}
