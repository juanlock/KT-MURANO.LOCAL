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
     * The invoice day for the period of $d, per the contract's day rule:
     * monthly / quarterly / yearly -- 'first' or 'last' day of the month, or
     * 'day' = $day (1-31, capped to the month's length, so 31 = month end);
     * weekly -- 'day' = weekday $day (1 = Monday ... 7 = Sunday), the same
     * week or the next. 'same' leaves the date as it is.
     */
    public static function align(Carbon $d, string $frequency, string $mode, ?int $day): Carbon
    {
        $d = $d->copy()->startOfDay();
        if ($frequency === 'weekly') {
            return $mode === 'day' && $day >= 1 && $day <= 7
                ? $d->addDays(($day - $d->dayOfWeekIso + 7) % 7)
                : $d;
        }

        return match ($mode) {
            'first' => $d->startOfMonth(),
            'last' => $d->endOfMonth()->startOfDay(),
            'day' => $day ? $d->day(min($day, $d->daysInMonth)) : $d,
            default => $d,
        };
    }

    /** The invoice date after $date: one period on, on the contract's day. */
    public static function nextDate(Carbon $date, string $frequency, int $interval, string $mode, ?int $day): Carbon
    {
        return self::align(self::advance($date, $frequency, $interval), $frequency, $mode, $day);
    }

    /** The first invoice date on/after $from that matches the day rule. */
    public static function firstDate(Carbon $from, string $frequency, int $interval, string $mode, ?int $day): Carbon
    {
        $d = self::align($from, $frequency, $mode, $day);

        return $d->lt($from->copy()->startOfDay()) ? self::nextDate($d, $frequency, $interval, $mode, $day) : $d;
    }

    /**
     * [start, end] of what the invoice dated $date bills. Invoiced on the
     * last day of the month = billed at the end of the period it covers
     * (Jan 31 bills Jan 1 - Jan 31); otherwise from the invoice date up to
     * the day before the next one (Feb 1 bills Feb 1 - Feb 28).
     */
    public static function period(Carbon $date, string $frequency, int $interval, string $mode, ?int $day): array
    {
        if ($mode === 'last' && $frequency !== 'weekly') {
            $months = ['quarterly' => 3, 'yearly' => 12][$frequency] ?? 1;

            return [$date->copy()->subMonthsNoOverflow($months * max(1, $interval))->endOfMonth()->addDay()->startOfDay(), $date->copy()];
        }

        return [$date->copy(), self::nextDate($date, $frequency, $interval, $mode, $day)->subDay()];
    }

    /**
     * The invoices a schedule creates: [{number, date, start, end}] from $first,
     * stopping at $count invoices (counting $already created ones), the
     * contract's end date, or $limit rows (open-ended contracts).
     */
    public static function schedule(Carbon $first, ?Carbon $endDate, string $frequency, int $interval, string $mode, ?int $day, ?int $count, int $already = 0, int $limit = 24): array
    {
        $rows = [];
        $d = $first->copy();
        $number = $already;
        while (count($rows) < $limit) {
            if ($count && $number >= $count) {
                break;
            }
            if ($endDate && $d->gt($endDate)) {
                break;
            }
            [$start, $end] = self::period($d, $frequency, $interval, $mode, $day);
            $rows[] = ['number' => ++$number, 'date' => $d->copy(), 'start' => $start, 'end' => $end];
            $d = self::nextDate($d, $frequency, $interval, $mode, $day);
        }

        return $rows;
    }

    /**
     * An invoice / line note with its placeholders filled for one invoice
     * (case-insensitive, unknown ones left as typed).
     * Of the period billed: {{month}} May, {{month_short}} May,
     * {{month_number}} 5, {{year}} 2026, {{quarter}} Q2, {{week}} 19 (ISO
     * week), {{date}} May 1, 2026 (period start), {{period}} May 1 - May 31, 2026.
     * Of the invoice date: {{invoice_date}} May 31, 2026, {{day}} 31,
     * {{weekday}} Friday, {{weekday_short}} Fri, {{invoice_month}} May.
     * Series: {{number}} / {{occurrence}} 3 (this invoice's number),
     * {{count}} 5 (how many in total; empty when open-ended).
     */
    public const NOTE_TOKENS = [
        'month', 'month_short', 'month_number', 'year', 'quarter', 'week', 'date', 'period',
        'invoice_date', 'day', 'weekday', 'weekday_short', 'invoice_month',
        'number', 'occurrence', 'count',
    ];

    /** Placeholders in $template that renderNote() doesn't know (e.g. a typo: {{mnth}}). */
    public static function unknownTokens(?string $template): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', (string) $template, $m);

        return array_values(array_unique(array_filter($m[1], fn ($t) => ! in_array(strtolower($t), self::NOTE_TOKENS, true))));
    }

    public static function renderNote(?string $template, Carbon $start, ?Carbon $end, int $number, ?int $count, ?Carbon $invoiceDate = null): ?string
    {
        $template = trim((string) $template);
        if ($template === '') {
            return null;
        }
        $end = $end ?: $start;
        $inv = $invoiceDate ?: $start;
        $values = [
            'invoice_date' => $inv->translatedFormat('M j, Y'),
            'day' => $inv->format('j'),
            'weekday' => $inv->translatedFormat('l'),
            'weekday_short' => $inv->translatedFormat('D'),
            'invoice_month' => $inv->translatedFormat('F'),
            'occurrence' => (string) $number,
            'month' => $start->translatedFormat('F'),
            'month_short' => $start->translatedFormat('M'),
            'month_number' => $start->format('n'),
            'year' => $start->format('Y'),
            'quarter' => 'Q'.$start->quarter,
            'week' => (string) $start->isoWeek(),
            'date' => $start->translatedFormat('M j, Y'),
            'period' => $start->translatedFormat('M j').' - '.$end->translatedFormat('M j, Y'),
            'number' => (string) $number,
            'count' => $count ? (string) $count : '',
        ];

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($m) use ($values) {
            $key = strtolower($m[1]);

            return array_key_exists($key, $values) ? $values[$key] : $m[0];
        }, $template);
    }

    /** Recurring invoices already created for the contract (with a billed period). */
    public static function periodInvoiceCount(SalesContract $contract): int
    {
        return SalesContractInvoice::where('sales_contract_id', $contract->id)->whereNotNull('period_start')->count();
    }

    /**
     * Create the invoice for the contract's current period.
     *
     * @return array{ok: bool, sale: ?Sale, reason: ?string}
     */
    /**
     * @param  bool  $final  create it final even when the contract makes drafts
     *                       (the invoices made while the user saves the form)
     */
    public function generate(SalesContract $contract, bool $automatic = false, ?int $userId = null, bool $final = false): array
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

        $frequency = (string) $contract->billing_frequency;
        $interval = (int) $contract->billing_interval;
        $mode = (string) ($contract->billing_day_mode ?: 'same');
        $day = $contract->billing_day ? (int) $contract->billing_day : null;
        $already = self::periodInvoiceCount($contract);
        if ($contract->billing_enabled && $contract->billing_count && $already >= $contract->billing_count) {
            return $this->fail("All {$contract->billing_count} invoices of this contract are already created.");
        }
        if ($contract->billing_enabled && ! $contract->next_invoice_date && $already > 0) {
            return $this->fail('The contract has no invoice left to create.');
        }

        // The invoice date (next_invoice_date) and the period it bills (see period()).
        $invoiceDate = Carbon::parse($contract->next_invoice_date ?: ($contract->billing_enabled ? $contract->start_date : now()))->startOfDay();
        [$periodStart, $periodEnd] = $contract->billing_enabled
            ? self::period($invoiceDate, $frequency, $interval, $mode, $day)
            : [$invoiceDate, null];
        $nextDate = $contract->billing_enabled ? self::nextDate($invoiceDate, $frequency, $interval, $mode, $day) : null;
        if ($contract->end_date && $invoiceDate->gt(Carbon::parse($contract->end_date))) {
            return $this->fail('The contract has ended: no period left to invoice.');
        }
        // This invoice's number in the series (1st, 2nd... of billing_count).
        $number = $already + 1;
        $isLast = $contract->billing_count && $number >= $contract->billing_count;

        return DB::transaction(function () use ($contract, $client, $warehouseId, $userId, $invoiceDate, $periodStart, $periodEnd, $nextDate, $number, $isLast, $automatic, $final) {
            $locked = SalesContract::whereKey($contract->id)->lockForUpdate()->first();
            // Same period already invoiced (double click / overlapping runs).
            if ($contract->billing_enabled && SalesContractInvoice::where('sales_contract_id', $locked->id)
                ->where('period_start', $periodStart->toDateString())->exists()) {
                return $this->fail('This period is already invoiced.');
            }

            $date = $automatic ? $invoiceDate->toDateString() : now()->toDateString();
            // Company price decimals (Defaults -> General: 2 or 3).
            $d = self::decimals((int) $contract->company_id);
            $lines = [];
            $subtotal = 0.0;
            foreach ($contract->items as $item) {
                $total = $item->total($d);
                $subtotal += $total;
                $lines[] = [$item, $total];
            }
            // The contract's Order Tax on the lines' total, like a sale's.
            $orderRate = (float) ($contract->order_tax_rate ?? 0);
            $orderTax = $orderRate > 0 ? round($subtotal * $orderRate / 100, $d) : 0.0;
            $grand = round($subtotal + $orderTax, $d);

            $sale = new Sale;
            $sale->is_pos = 0;
            $sale->date = $date;
            $sale->time = now()->toTimeString();
            $sale->Ref = app(SalesController::class)->getNumberOrder();
            $sale->client_id = $client->id;
            $sale->warehouse_id = $warehouseId;
            $sale->company_id = (int) $contract->company_id;
            $sale->tax_rate = $orderRate;
            $sale->TaxNet = $orderTax;
            $sale->tax_method = '1';
            $sale->discount = 0;
            $sale->discount_Method = '2';
            $sale->shipping = 0;
            $sale->GrandTotal = $grand;
            $sale->paid_amount = 0;
            // invoice_as_draft: a draft (Pending) to review and finalize --
            // no stock out until it's completed. Else final (Completed), unpaid.
            $asDraft = ! $final && (bool) ($contract->invoice_as_draft ?? false);
            $sale->statut = $asDraft ? 'pending' : 'completed';
            $sale->is_draft = $asDraft;
            $sale->payment_statut = 'unpaid';
            // The contract's invoice note filled for this invoice ("Service
            // made on {{weekday}}, {{date}}"); without one, the automatic text.
            $sale->notes = self::renderNote($contract->invoice_note, $periodStart, $periodEnd, $number, $contract->billing_count ?: null, $invoiceDate)
                ?? trim('Sales contract '.$contract->contract_number.' - '.$contract->subject
                    .($periodEnd ? ' ('.$periodStart->toDateString().' to '.$periodEnd->toDateString().')' : ''));
            $sale->user_id = $userId;
            $sale->save();

            foreach ($lines as [$item, $total]) {
                $product = $item->product_id ? Product::find($item->product_id) : null;
                // The line's note template filled for this invoice ("Note: May
                // installment services"); without one, the old behavior: the
                // line description when it differs from the product name.
                $note = self::renderNote($item->note, $periodStart, $periodEnd, $number, $contract->billing_count ?: null, $invoiceDate)
                    ?? ($product && $item->name !== $product->name ? $item->name : null);
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
                    'note' => $note,
                ], $product ? SaleDetail::computeWarrantyGuaranteeDates($product, $date) : []));
                if ($product && $product->type !== 'is_service' && ! $asDraft) {
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
                // After the last of billing_count invoices there is no next one.
                'next_invoice_date' => $contract->billing_enabled && $nextDate
                    ? ($isLast ? null : $nextDate->toDateString())
                    : $locked->next_invoice_date,
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

    /** The company's money precision (Settings -> Defaults -> General -> Price decimals). */
    public static function decimals(int $companyId): int
    {
        return (bool) (Setting::forCompany($companyId)->enable_3_decimal_pricing ?? false) ? 3 : 2;
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
