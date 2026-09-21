<?php

namespace App\Services\Xero;

use App\Models\Client as Customer;
use App\Models\Sale;
use App\Models\XeroLog;
use App\Models\XeroMapping;
use App\Models\XeroSetting;
use Illuminate\Support\Facades\DB;

/**
 * Xero sync engine: pushes Stocky clients as Xero Contacts and sales as
 * ACCREC invoices (plus a Payment when a bank account code is configured).
 *
 * Batch methods are cursor-based (`last_id` + `has_more`) so the frontend
 * loop (useBatchSync) drives them exactly like the Salla/Shopify tabs.
 * Invoices are treated as write-once: an already-mapped sale is skipped —
 * Xero restricts edits on authorised/paid invoices, and the unique
 * InvoiceNumber (the sale Ref) guards against duplicates on Xero's side too.
 */
class SyncService
{
    private XeroSetting $settings;
    private Client $client;

    public function __construct(XeroSetting $settings, ?Client $client = null)
    {
        $this->settings = $settings;
        $this->client = $client ?: Client::make($settings);
    }

    public static function make(?XeroSetting $settings = null): self
    {
        return new self($settings ?: XeroSetting::current());
    }

    /**
     * Rides the WebhookDispatcher fan-out (same shape as MailchimpService):
     * queues an invoice push when auto-sync is on and a sale was created.
     */
    public static function handleEvent(string $event, array $payload): void
    {
        try {
            if ($event !== 'sale.created') {
                return;
            }

            $settings = XeroSetting::query()->first();
            if (! $settings || ! $settings->enabled || ! $settings->auto_sync || ! $settings->isConnected()) {
                return;
            }

            $saleId = (int) ($payload['id'] ?? 0);
            if ($saleId) {
                \App\Jobs\Xero\XeroSaleSyncJob::dispatch($saleId)->onQueue('webhooks');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    public function testConnection(): array
    {
        $res = $this->client->get('Organisation');
        if (! $res->successful()) {
            return ['ok' => false, 'status' => $res->status(), 'error' => Client::error($res)];
        }

        $organisation = $res->json()['Organisations'][0] ?? [];

        return [
            'ok' => true,
            'organisation' => [
                'name' => $organisation['Name'] ?? null,
                'country' => $organisation['CountryCode'] ?? null,
                'currency' => $organisation['BaseCurrency'] ?? null,
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Contacts
    // -----------------------------------------------------------------

    public function pushContacts(int $startAfterId = 0, int $batch = 25): array
    {
        $clients = Customer::whereNull('deleted_at')
            ->where('id', '>', $startAfterId)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($clients as $client) {
            $lastId = (int) $client->id;
            try {
                $wasMapped = XeroMapping::xeroId(XeroMapping::TYPE_CONTACT, (int) $client->id) !== null;
                $this->pushSingleContact($client);
                $wasMapped ? $updated++ : $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['client_id' => $client->id, 'name' => $client->name, 'error' => $e->getMessage()];
            }
        }

        $this->settings->forceFill(['last_sync_at' => now()])->save();
        $this->log('contacts.push', $failed ? 'warning' : 'info', "Contacts push batch: {$created} created, {$updated} updated, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $clients->count(),
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $clients->count() === $batch,
        ];
    }

    /** Upsert one client as a Xero Contact; returns the ContactID. */
    public function pushSingleContact(Customer $client): string
    {
        $payload = ['Name' => trim((string) $client->name) ?: 'Client '.$client->id];
        if (trim((string) $client->email) !== '') {
            $payload['EmailAddress'] = trim((string) $client->email);
        }
        if (trim((string) $client->phone) !== '') {
            $payload['Phones'] = [['PhoneType' => 'MOBILE', 'PhoneNumber' => trim((string) $client->phone)]];
        }

        $existingId = XeroMapping::xeroId(XeroMapping::TYPE_CONTACT, (int) $client->id);
        if ($existingId) {
            $payload['ContactID'] = $existingId;
        }

        $res = $this->client->post('Contacts', ['Contacts' => [$payload]]);

        // Contact names are unique in Xero: an unmapped client whose name is
        // taken gets linked to the existing contact instead of failing forever.
        if (! $res->successful() && ! $existingId) {
            $found = $this->lookupContactByName($payload['Name']);
            if ($found) {
                XeroMapping::put(XeroMapping::TYPE_CONTACT, (int) $client->id, $found);

                return $found;
            }
        }

        if (! $res->successful()) {
            throw new \RuntimeException(Client::error($res));
        }

        $contactId = (string) ($res->json()['Contacts'][0]['ContactID'] ?? '');
        if ($contactId === '') {
            throw new \RuntimeException('Xero returned no ContactID');
        }

        XeroMapping::put(XeroMapping::TYPE_CONTACT, (int) $client->id, $contactId);

        return $contactId;
    }

    private function lookupContactByName(string $name): ?string
    {
        $res = $this->client->get('Contacts', [
            'where' => 'Name=="'.str_replace('"', '', $name).'"',
        ]);
        if (! $res->successful()) {
            return null;
        }

        $id = $res->json()['Contacts'][0]['ContactID'] ?? null;

        return $id ? (string) $id : null;
    }

    private function ensureContactId(?int $clientId): string
    {
        $client = $clientId ? Customer::find($clientId) : null;
        if (! $client) {
            throw new \RuntimeException('Sale has no client to invoice');
        }

        return XeroMapping::xeroId(XeroMapping::TYPE_CONTACT, (int) $client->id)
            ?: $this->pushSingleContact($client);
    }

    // -----------------------------------------------------------------
    // Invoices
    // -----------------------------------------------------------------

    public function pushInvoices(int $startAfterId = 0, int $batch = 10): array
    {
        $sales = Sale::whereNull('deleted_at')
            ->where('id', '>', $startAfterId)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($sales as $sale) {
            $lastId = (int) $sale->id;

            if (XeroMapping::xeroId(XeroMapping::TYPE_INVOICE, (int) $sale->id)) {
                $skipped++;
                continue;
            }

            try {
                $this->pushSingleInvoice($sale);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['sale_id' => $sale->id, 'ref' => $sale->Ref, 'error' => $e->getMessage()];
            }
        }

        $this->settings->forceFill(['last_sync_at' => now()])->save();
        $this->log('invoices.push', $failed ? 'warning' : 'info', "Invoices push batch: {$created} created, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $sales->count(),
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $sales->count() === $batch,
        ];
    }

    public function pushSingleInvoice(Sale $sale): string
    {
        $contactId = $this->ensureContactId($sale->client_id ? (int) $sale->client_id : null);
        $accountCode = trim((string) $this->settings->sales_account_code) ?: '200';

        $lines = [];
        $details = DB::table('sale_details')
            ->leftJoin('products', 'products.id', '=', 'sale_details.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'sale_details.product_variant_id')
            ->where('sale_details.sale_id', $sale->id)
            ->selectRaw('sale_details.quantity, sale_details.price, products.name as product_name, products.code as product_code, product_variants.name as variant_name, product_variants.code as variant_code')
            ->get();

        foreach ($details as $detail) {
            $description = trim((string) ($detail->product_name ?? 'Item'))
                .($detail->variant_name ? ' — '.$detail->variant_name : '');
            $code = $detail->variant_code ?: $detail->product_code;
            if ($code) {
                $description .= ' ('.$code.')';
            }
            $lines[] = [
                'Description' => $description,
                'Quantity' => (float) $detail->quantity,
                'UnitAmount' => (float) $detail->price,
                'AccountCode' => $accountCode,
            ];
        }

        if (! $lines) {
            throw new \RuntimeException('Sale has no line items');
        }

        // Keep the Xero total equal to GrandTotal: shipping/discount/tax go on
        // dedicated NoTax lines (the accountant can remap accounts in Xero).
        if ((float) $sale->shipping > 0) {
            $lines[] = ['Description' => 'Shipping', 'Quantity' => 1.0, 'UnitAmount' => (float) $sale->shipping, 'AccountCode' => $accountCode];
        }
        if ((float) $sale->discount > 0) {
            $lines[] = ['Description' => 'Discount', 'Quantity' => 1.0, 'UnitAmount' => -1 * (float) $sale->discount, 'AccountCode' => $accountCode];
        }
        if ((float) $sale->TaxNet > 0) {
            $lines[] = ['Description' => 'Tax', 'Quantity' => 1.0, 'UnitAmount' => (float) $sale->TaxNet, 'AccountCode' => $accountCode];
        }

        $invoice = [
            'Type' => 'ACCREC',
            'Contact' => ['ContactID' => $contactId],
            'Date' => (string) $sale->date,
            'DueDate' => (string) $sale->date,
            'InvoiceNumber' => (string) $sale->Ref,
            'Reference' => 'Stocky sale #'.$sale->id,
            'Status' => 'AUTHORISED',
            'LineAmountTypes' => 'NoTax',
            'LineItems' => $lines,
        ];

        $res = $this->client->post('Invoices', ['Invoices' => [$invoice]]);
        if (! $res->successful()) {
            throw new \RuntimeException(Client::error($res));
        }

        $invoiceId = (string) ($res->json()['Invoices'][0]['InvoiceID'] ?? '');
        if ($invoiceId === '') {
            throw new \RuntimeException('Xero returned no InvoiceID');
        }

        XeroMapping::put(XeroMapping::TYPE_INVOICE, (int) $sale->id, $invoiceId, [
            'invoice_number' => (string) $sale->Ref,
        ]);

        $this->maybePushPayment($sale, $invoiceId);

        return $invoiceId;
    }

    /** Record the sale's paid amount against the invoice, when configured. */
    private function maybePushPayment(Sale $sale, string $invoiceId): void
    {
        $accountCode = trim((string) $this->settings->payment_account_code);
        if ($accountCode === '' || (float) $sale->paid_amount <= 0) {
            return;
        }
        if (XeroMapping::xeroId(XeroMapping::TYPE_PAYMENT, (int) $sale->id)) {
            return;
        }

        $res = $this->client->put('Payments', ['Payments' => [[
            'Invoice' => ['InvoiceID' => $invoiceId],
            'Account' => ['Code' => $accountCode],
            'Date' => (string) $sale->date,
            'Amount' => (float) $sale->paid_amount,
        ]]]);

        if (! $res->successful()) {
            // The invoice made it — a payment failure is logged, not fatal.
            $this->log('payments.push', 'warning', 'Payment not recorded for sale '.$sale->Ref.': '.Client::error($res), [
                'sale_id' => $sale->id,
            ]);

            return;
        }

        $paymentId = (string) ($res->json()['Payments'][0]['PaymentID'] ?? '');
        if ($paymentId !== '') {
            XeroMapping::put(XeroMapping::TYPE_PAYMENT, (int) $sale->id, $paymentId);
        }
    }

    // -----------------------------------------------------------------
    // Stats / reset / logging
    // -----------------------------------------------------------------

    public function stats(): array
    {
        $count = fn (string $type) => XeroMapping::where('entity_type', $type)->count();

        return [
            'clients_total' => Customer::whereNull('deleted_at')->count(),
            'contacts_mapped' => $count(XeroMapping::TYPE_CONTACT),
            'sales_total' => Sale::whereNull('deleted_at')->count(),
            'invoices_synced' => $count(XeroMapping::TYPE_INVOICE),
            'payments_synced' => $count(XeroMapping::TYPE_PAYMENT),
        ];
    }

    public function resetMappings(string $entityType): int
    {
        return XeroMapping::where('entity_type', $entityType)->delete();
    }

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            XeroLog::create([
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a sync
        }
    }
}
