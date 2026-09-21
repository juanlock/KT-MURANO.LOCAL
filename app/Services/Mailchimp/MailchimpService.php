<?php

namespace App\Services\Mailchimp;

use App\Jobs\Mailchimp\MailchimpUpsertJob;
use App\Models\Client as Customer;
use App\Models\MailchimpLog;
use App\Models\MailchimpSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Mailchimp audience sync: pushes Stocky customers (those with an email) as
 * audience members. Members are addressed by md5(lower(email)), so every push
 * is an idempotent upsert — no mapping table needed.
 *
 * Consent: with double_opt_in on (the default), NEW members join as 'pending'
 * and Mailchimp emails them a confirmation, keeping the tenant on the right
 * side of GDPR/CAN-SPAM; existing members' subscription status is never
 * overwritten. handleEvent() rides the WebhookDispatcher fan-out and queues
 * one upsert per new customer when auto_sync is enabled.
 */
class MailchimpService
{
    private MailchimpSetting $settings;

    public function __construct(MailchimpSetting $settings)
    {
        $this->settings = $settings;
    }

    public static function make(?MailchimpSetting $settings = null): self
    {
        return new self($settings ?: MailchimpSetting::current());
    }

    // -----------------------------------------------------------------
    // Event fan-out (called from WebhookDispatcher::dispatch)
    // -----------------------------------------------------------------

    public static function handleEvent(string $event, array $payload): void
    {
        try {
            if ($event !== 'client.created') {
                return;
            }

            $settings = MailchimpSetting::query()->first();
            if (! $settings || ! $settings->enabled || ! $settings->auto_sync || ! $settings->isReady()) {
                return;
            }

            $clientId = (int) ($payload['id'] ?? 0);
            if ($clientId) {
                MailchimpUpsertJob::dispatch($clientId)->onQueue('webhooks');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    // -----------------------------------------------------------------
    // API
    // -----------------------------------------------------------------

    public function testConnection(): array
    {
        $res = $this->request('get', 'ping');
        if (! $res->successful()) {
            return ['ok' => false, 'status' => $res->status(), 'error' => self::error($res)];
        }

        return ['ok' => true, 'health' => $res->json()['health_status'] ?? null];
    }

    /** The account's audiences, for the settings page picker. */
    public function lists(): array
    {
        $res = $this->request('get', 'lists', ['count' => 100, 'fields' => 'lists.id,lists.name,lists.stats.member_count']);
        if (! $res->successful()) {
            throw new RuntimeException(self::error($res));
        }

        return array_map(fn ($list) => [
            'id' => $list['id'] ?? '',
            'name' => $list['name'] ?? '',
            'member_count' => $list['stats']['member_count'] ?? null,
        ], (array) ($res->json()['lists'] ?? []));
    }

    /** Cursor-batched customer push (customers without an email are skipped). */
    public function pushCustomers(int $startAfterId = 0, int $batch = 50): array
    {
        if (! $this->settings->isReady()) {
            return ['ok' => false, 'error' => 'Pick a Mailchimp audience first.'];
        }

        $customers = Customer::whereNull('deleted_at')
            ->where('id', '>', $startAfterId)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $updated = 0;
        $failed = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($customers as $customer) {
            $lastId = (int) $customer->id;
            try {
                $this->upsertMember($customer);
                $updated++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = ['client_id' => $customer->id, 'email' => $customer->email, 'error' => $e->getMessage()];
            }
        }

        $hasMore = $customers->count() === $batch;
        if (! $hasMore) {
            $this->settings->forceFill(['last_sync_at' => now()])->save();
        }

        $this->log('customers.push', $failed ? 'warning' : 'info', "Customers push batch: {$updated} upserted, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $customers->count(),
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $hasMore,
        ];
    }

    /** Idempotent member upsert; never downgrades an existing member's status. */
    public function upsertMember(Customer $customer): void
    {
        $email = strtolower(trim((string) $customer->email));
        if ($email === '') {
            throw new RuntimeException('Customer has no email');
        }

        $name = trim((string) $customer->name);
        $parts = preg_split('/\s+/', $name, 2) ?: [];

        $payload = [
            'email_address' => $email,
            'status_if_new' => $this->settings->double_opt_in ? 'pending' : 'subscribed',
            'merge_fields' => array_filter([
                'FNAME' => $parts[0] ?? null,
                'LNAME' => $parts[1] ?? null,
                'PHONE' => trim((string) $customer->phone) ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
        if (! $payload['merge_fields']) {
            unset($payload['merge_fields']);
        }

        $res = $this->request(
            'put',
            'lists/'.$this->settings->list_id.'/members/'.md5($email),
            $payload
        );
        if (! $res->successful()) {
            throw new RuntimeException(self::error($res));
        }
    }

    public function stats(): array
    {
        return [
            'customers_total' => Customer::whereNull('deleted_at')->count(),
            'customers_with_email' => Customer::whereNull('deleted_at')
                ->whereNotNull('email')->where('email', '!=', '')->count(),
        ];
    }

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            MailchimpLog::create([
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (Throwable $e) {
            // Logging must never break a sync
        }
    }

    // -----------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------

    private function request(string $method, string $path, array $data = []): Response
    {
        $key = (string) $this->settings->api_key;
        if ($key === '') {
            throw new RuntimeException('Mailchimp is not configured — add the API key first.');
        }

        // The datacenter is the key's suffix: xxxxxxxx-us14 -> us14.
        $dc = str_contains($key, '-') ? substr($key, strrpos($key, '-') + 1) : '';
        if ($dc === '') {
            throw new RuntimeException('Invalid Mailchimp API key: missing the "-usX" datacenter suffix.');
        }

        return Http::withBasicAuth('stocky', $key)
            ->acceptJson()
            ->timeout(30)
            ->{$method}('https://'.$dc.'.api.mailchimp.com/3.0/'.ltrim($path, '/'), $data);
    }

    public static function error(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $detail = (string) ($json['detail'] ?? '');
            $title = (string) ($json['title'] ?? '');
            if ($detail !== '' || $title !== '') {
                return trim($title.($detail !== '' ? ': '.$detail : ''), ': ');
            }
        }

        return 'HTTP '.$response->status();
    }
}
