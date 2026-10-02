<?php

namespace App\Services;

use App\Http\Controllers\SalesController;
use App\Models\Client;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalesContract;
use App\Models\SalesContractInvoice;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sales Contracts -> recurring invoices. Each invoice is a normal Sale
 * (status completed, UNPAID -- collected later like any invoice) with one
 * line per contract item, in the contract's company / customer / location,
 * dated on the period it bills. Physical products leave stock like any sale;
 * services don't. The contract's next_invoice_date then moves one period on.
 *
 * Used by the "Generate invoice now" button and by the daily
 * `sales-contracts:invoice` command (every active contract whose
 * next_invoice_date has come, catching up missed periods, max 12 per run).
 */
class SalesContractBilling
{
    /** Next date after $from for the contract's frequency x interval. */
    public static function advance(Carbon $from, string $frequency, int $interval): Carbon
    {
        $n = max(1, $interval);

        return match ($frequency) {
            'weekly' => $from->copy()->addWeeks($n),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3 * $n),
            'yearly' => $from->copy()->addYearsNoOverflow($n),
            default => $from->copy()->addMonthsNoOverflow($n),
        };
    }

    /**
     * Create the invoice for the contract's current period.
     *
     * @return array{ok: bool, sale: ?Sale, reason: ?string}
     */
    public function generate(SalesContract $contract, bool $automatic = false, ?int $userId = null): array
    {
        $contract->loadMissing('items');
        if ($contract->status !== 'active') {
            return $this->fail('Only active contracts are invoiced.');
        }
        if ($contract->items->isEmpty()) {
            return $this->fail('The contract has no lines to invoice.');
        }
        $client = Client::whereNull('deleted_at')->find($contract->client_id);
        if (! $client || (int) $client->company_id !== (int) $contract->company_id) {
            return $this->fail('The contract customer no longer exists in this company.');
        }
        $warehouseId = $this->warehouseFor($contract);
        if (! $warehouseId) {
            return $this->fail('The company has no location to put the invoice on.');
        }
        $userId = $userId ?: $this->userFor($contract);
        if (! $userId) {
            return $this->fail('No user of the company to record the invoice under.');
        }

        $periodStart = Carbon::parse($contract->next_invoice_date ?: ($contract->billing_enabled ? $contract->start_date : now()));
        $periodEnd = $contract->billing_enabled
            ? self::advance($periodStart, (string) $contract->billing_frequency, (int) $contract->billing_interval)->subDay()
            : null;
        if ($contract->end_date && $periodStart->gt(Carbon::parse($contract->end_date))) {
            return $this->fail('The contract has ended: no period left to invoice.');
        }

        return DB::transaction(function () use ($contract, $client, $warehouseId, $userId, $periodStart, $periodEnd, $automatic) {
            $locked = SalesContract::whereKey($contract->id)->lockForUpdate()->first();
            // Same period already invoiced (double click / overlapping runs).
            if ($contract->billing_enabled && SalesContractInvoice::where('sales_contract_id', $locked->id)
                ->where('period_start', $periodStart->toDateString())->exists()) {
                return $this->fail('This period is already invoiced.');
            }

            $date = $automatic ? $periodStart->toDateString() : now()->toDateString();
            $lines = [];
            $grand = 0.0;
            foreach ($contract->items as $item) {
                $total = $item->total();
                $grand += $total;
                $lines[] = [$item, $total];
            }

            $sale = new Sale;
            $sale->is_pos = 0;
            $sale->date = $date;
            $sale->time = now()->toTimeString();
            $sale->Ref = app(SalesController::class)->getNumberOrder();
            $sale->client_id = $client->id;
            $sale->warehouse_id = $warehouseId;
            $sale->company_id = (int) $contract->company_id;
            $sale->tax_rate = 0;
            $sale->TaxNet = 0;
            $sale->tax_method = '1';
            $sale->discount = 0;
            $sale->discount_Method = '2';
            $sale->shipping = 0;
            $sale->GrandTotal = round($grand, 2);
            $sale->paid_amount = 0;
            $sale->statut = 'completed';
            $sale->payment_statut = 'unpaid';
            $sale->notes = trim('Sales contract '.$contract->contract_number.' - '.$contract->subject
                .($periodEnd ? ' ('.$periodStart->toDateString().' to '.$periodEnd->toDateString().')' : ''));
            $sale->user_id = $userId;
            $sale->save();

            foreach ($lines as [$item, $total]) {
                $product = $item->product_id ? Product::find($item->product_id) : null;
                SaleDetail::create(array_merge([
                    'date' => $date,
                    'sale_id' => $sale->id,
                    'product_id' => $product?->id,
                    'product_variant_id' => null,
                    'sale_unit_id' => $product?->unit_sale_id ?: null,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'TaxNet' => $item->tax_percent,
                    'tax_method' => $item->tax_method ?: '1',
                    'discount' => 0,
                    'discount_method' => '2',
                    'total' => $total,
                    'price_type' => 'retail',
                    'pack_multiplier' => 1,
                    'note' => $product && $item->name !== $product->name ? $item->name : null,
                ], $product ? SaleDetail::computeWarrantyGuaranteeDates($product, $date) : []));
                if ($product && $product->type !== 'is_service') {
                    $this->deductStock($product, $warehouseId, (float) $item->quantity);
                }
            }

            SalesContractInvoice::create([
                'sales_contract_id' => $locked->id,
                'sale_id' => $sale->id,
                'period_start' => $contract->billing_enabled ? $periodStart->toDateString() : null,
                'period_end' => $periodEnd?->toDateString(),
                'automatic' => $automatic,
            ]);
            $locked->forceFill([
                'last_invoice_date' => $date,
                'next_invoice_date' => $contract->billing_enabled && $periodEnd ? $periodEnd->copy()->addDay()->toDateString() : $locked->next_invoice_date,
            ])->save();
            $contract->setRawAttributes($locked->getAttributes(), true);

            DB::afterCommit(function () use ($sale) {
                try {
                    app(CommissionService::class)->calculateForSale($sale->fresh());
                } catch (\Throwable $e) {
                    Log::warning('Sales contract invoice commission failed (non-blocking): '.$e->getMessage(), ['sale_id' => $sale->id]);
                }
            });

            return ['ok' => true, 'sale' => $sale, 'reason' => null];
        });
    }

    /** Daily run: every due period of every active recurring contract (max 12 per contract). */
    public function generateDue(?Carbon $today = null): array
    {
        $today = ($today ?: now())->toDateString();
        $done = [];
        $contracts = SalesContract::whereNull('deleted_at')->where('status', 'active')->where('billing_enabled', true)
            ->whereNotNull('next_invoice_date')->where('next_invoice_date', '<=', $today)->get();
        foreach ($contracts as $contract) {
            for ($i = 0; $i < 12; $i++) {
                $contract->refresh();
                if (! $contract->next_invoice_date || $contract->next_invoice_date > $today
                    || ($contract->end_date && $contract->next_invoice_date > $contract->end_date)) {
                    break;
                }
                try {
                    $r = $this->generate($contract, true);
                } catch (\Throwable $e) {
                    Log::warning('Sales contract invoice failed: '.$e->getMessage(), ['contract_id' => $contract->id]);
                    break;
                }
                if (! $r['ok']) {
                    break;
                }
                $done[] = ['contract' => $contract->contract_number, 'sale' => $r['sale']->Ref, 'total' => (float) $r['sale']->GrandTotal];
            }
        }

        return $done;
    }

    private function fail(string $reason): array
    {
        return ['ok' => false, 'sale' => null, 'reason' => $reason];
    }

    /** The contract's location, else the company default, else its first location. */
    private function warehouseFor(SalesContract $contract): ?int
    {
        $companyId = (int) $contract->company_id;
        $ok = fn ($id) => $id && Warehouse::whereKey($id)->where('company_id', $companyId)->whereNull('deleted_at')->exists();
        if ($ok($contract->warehouse_id)) {
            return (int) $contract->warehouse_id;
        }
        $default = Setting::forCompany($companyId)->warehouse_id ?? null;
        if ($ok($default)) {
            return (int) $default;
        }

        return Warehouse::where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    /** Signed-in user, else who created the contract, else the company's first active admin/user. */
    private function userFor(SalesContract $contract): ?int
    {
        if (auth()->id()) {
            return (int) auth()->id();
        }
        if ($contract->created_by && User::whereKey($contract->created_by)->whereNull('deleted_at')->exists()) {
            return (int) $contract->created_by;
        }

        return User::where('company_id', $contract->company_id)->whereNull('deleted_at')->where('statut', 1)
            ->orderByRaw("type = 'admin' desc")->orderBy('id')->value('id');
    }

    private function deductStock(Product $product, int $warehouseId, float $qty): void
    {
        $pw = product_warehouse::whereNull('deleted_at')->where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id)->whereNull('product_variant_id')->first();
        $unit = $product->unit_sale_id ? Unit::find($product->unit_sale_id) : null;
        if (! $pw || ! $unit) {
            return;
        }
        $pw->qte -= $unit->operator === '/' ? $qty / $unit->operator_value : $qty * $unit->operator_value;
        $pw->save();
    }
}
