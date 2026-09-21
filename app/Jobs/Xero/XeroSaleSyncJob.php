<?php

namespace App\Jobs\Xero;

use App\Models\Sale;
use App\Models\XeroMapping;
use App\Models\XeroSetting;
use App\Services\Xero\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Auto-sync: pushes one sale to Xero as an invoice (+ payment when a bank
 * account code is configured). Settings are re-read at run time, so toggling
 * auto-sync off cancels queued work; already-mapped sales are skipped, making
 * the job idempotent across retries.
 */
class XeroSaleSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Several sequential Xero calls (contact + invoice + payment). */
    public int $timeout = 120;

    public function __construct(public int $saleId)
    {
    }

    /** Backoff schedule (seconds): 1m, 5m. */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $settings = XeroSetting::query()->first();
        if (! $settings || ! $settings->enabled || ! $settings->auto_sync || ! $settings->isConnected()) {
            return;
        }

        $sale = Sale::whereNull('deleted_at')->find($this->saleId);
        if (! $sale || XeroMapping::xeroId(XeroMapping::TYPE_INVOICE, (int) $sale->id)) {
            return; // gone, or already invoiced — nothing to do
        }

        $service = SyncService::make($settings);
        try {
            $service->pushSingleInvoice($sale);
            $service->log('auto_sync.invoice', 'info', 'Sale auto-synced to Xero: '.$sale->Ref, ['sale_id' => $sale->id]);
        } catch (\Throwable $e) {
            $service->log('auto_sync.invoice', 'warning', 'Auto-sync failed for '.$sale->Ref.': '.$e->getMessage(), ['sale_id' => $sale->id]);
            throw $e; // retry per backoff()
        }
    }
}
