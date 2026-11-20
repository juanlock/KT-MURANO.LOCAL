<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Distribución B2B (2026-11-20, Hordex): the shared rules of the module.
 *
 *  - Guides: the next number of a series; a dispatched guide takes its goods out of the warehouse (product_warehouse),
 *    a cancelled one puts them back (only when the guide moved them: imported history never touches the stock).
 *  - Invoicing guides: one or several dispatched guides of the same client become ONE sale (the company's B2B series,
 *    e.g. E001-000595), IGV on top (tax_rate 18), the lines grouped by product; the guides are marked invoiced. The goods
 *    already left with the guides, so the sale does not touch the stock again.
 *  - Collections: an invoice is settled in lines (bank deposit, SUNAT detracción, the chain's deductions); each line is a
 *    payment_sales row (+ b2b_payment_info) and the sale's paid amount / status follow. All in one transaction.
 */
class B2b
{
    public const IGV = 18.0;

    /** concept kind => the payment method name it is recorded with (payment_methods is install-wide). */
    public const METHODS = ['deposit' => 'Depósito bancario', 'detraction' => 'Detracción', 'rebate' => 'Deducción comercial', 'deduction' => 'Deducción comercial'];

    public static function settings(int $cid): array
    {
        $raw = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('b2b_settings');
        $s = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        return $s + ['invoice_series' => 'E001', 'guide_series' => 'EG07', 'igv' => self::IGV, 'warehouse_id' => null];
    }

    /**
     * The next number of a series: the company's counter (b2b_settings.next[series], set by the import from the LAST document
     * by date -- Hordex had a mistyped E001-572000 that a max() would follow), else the highest + 1; never one already used.
     */
    public static function nextGuideNumber(int $cid, string $series): int
    {
        return self::next($cid, $series, 'b2b_guides');
    }

    public static function nextInvoiceNumber(int $cid, string $series): int
    {
        return self::next($cid, $series, 'b2b_sale_info');
    }

    private static function next(int $cid, string $series, string $table): int
    {
        $n = (int) (self::settings($cid)['next'][$series] ?? 0);
        if ($n <= 0) {
            $n = (int) DB::table($table)->where('company_id', $cid)->where('series', $series)->max('number') + 1;
        }
        while (DB::table($table)->where('company_id', $cid)->where('series', $series)->where('number', $n)->exists()) {
            $n++;
        }

        return $n;
    }

    /** After a document takes number $n of $series, the counter moves to $n + 1. */
    public static function used(int $cid, string $series, int $n): void
    {
        $s = self::settings($cid);
        $s['next'][$series] = max($n + 1, (int) ($s['next'][$series] ?? 0));
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['b2b_settings' => json_encode($s)]);
    }

    public static function ref(string $series, int $number): string
    {
        return $series.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public static function warehouse(int $cid): ?int
    {
        $w = self::settings($cid)['warehouse_id'] ?? null;

        return $w ? (int) $w : ((int) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('warehouse_id') ?: null);
    }

    /** Goods out (+1 = back in) for a guide's items at its warehouse. */
    public static function moveStock(int $guideId, int $sign): void
    {
        $g = DB::table('b2b_guides')->where('id', $guideId)->first();
        if (! $g || ! $g->warehouse_id) {
            return;
        }
        foreach (DB::table('b2b_guide_items')->where('guide_id', $guideId)->whereNotNull('product_id')->get() as $it) {
            $row = DB::table('product_warehouse')->where('product_id', $it->product_id)->where('warehouse_id', $g->warehouse_id)
                ->whereNull('product_variant_id')->whereNull('deleted_at')->first();
            $delta = $sign * (float) $it->quantity;
            if ($row) {
                DB::table('product_warehouse')->where('id', $row->id)->update(['qte' => (float) $row->qte + $delta, 'updated_at' => now()]);
            } else {
                DB::table('product_warehouse')->insert(['product_id' => $it->product_id, 'warehouse_id' => $g->warehouse_id, 'qte' => $delta,
                    'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * The guides (same client, dispatched, not invoiced) -> one sale. $prices = {product_id: unit price without IGV};
     * missing = the product's price. Returns the sale id.
     */
    public static function invoiceGuides(int $cid, array $guideIds, int $userId, array $data): int
    {
        return DB::transaction(function () use ($cid, $guideIds, $userId, $data) {
            $guides = DB::table('b2b_guides')->where('company_id', $cid)->whereIn('id', $guideIds)->lockForUpdate()->get();
            abort_if($guides->count() !== count(array_unique($guideIds)), 404, __('Guide not found.'));
            abort_if($guides->pluck('client_id')->unique()->count() !== 1, 422, __('The guides must be of the same client.'));
            abort_if($guides->contains(fn ($g) => $g->status !== 'dispatched'), 422, __('Only dispatched guides that are not invoiced yet can be invoiced.'));

            $prices = $data['prices'] ?? [];
            $lines = [];
            foreach (DB::table('b2b_guide_items')->whereIn('guide_id', $guideIds)->get() as $it) {
                $k = $it->product_id ?: 'd'.$it->id;
                $lines[$k] ??= ['product_id' => $it->product_id, 'quantity' => 0];
                $lines[$k]['quantity'] += (float) $it->quantity;
            }
            foreach ($lines as $k => $l) {
                $lines[$k]['price'] = isset($prices[$l['product_id']]) ? (float) $prices[$l['product_id']]
                    : (float) DB::table('products')->where('id', $l['product_id'])->value('price');
            }
            $data['purchase_order'] ??= $guides->pluck('purchase_order')->filter()->unique()->implode(', ') ?: null;
            $saleId = self::createInvoice($cid, $userId, (int) $guides->first()->client_id, array_values($lines), $data, $guides->first()->warehouse_id);
            DB::table('b2b_guides')->whereIn('id', $guideIds)->update(['sale_id' => $saleId, 'status' => 'invoiced', 'updated_at' => now()]);

            return $saleId;
        });
    }

    /**
     * One invoice (a sale + b2b_sale_info), from guides or typed directly (Hordex's "Registrar Nueva Factura", 2026-11-20).
     * $lines = [{product_id, quantity, price (without IGV)}]; $data: date, series, number (typed; else the next one),
     * payment_term_id, purchase_order, notes. Runs inside the caller's transaction or its own.
     */
    public static function createInvoice(int $cid, int $userId, int $clientId, array $lines, array $data, ?int $warehouseId = null): int
    {
        return DB::transaction(function () use ($cid, $userId, $clientId, $lines, $data, $warehouseId) {
            $s = self::settings($cid);
            $series = strtoupper(trim($data['series'] ?? '') ?: $s['invoice_series']);
            $number = ! empty($data['number']) ? (int) $data['number'] : self::nextInvoiceNumber($cid, $series);
            abort_if(DB::table('b2b_sale_info')->where('company_id', $cid)->where('series', $series)->where('number', $number)->exists()
                || DB::table('sales')->where('company_id', $cid)->whereNull('deleted_at')->where('Ref', self::ref($series, $number))->exists(),
                422, __('That invoice number already exists.'));
            $date = $data['date'] ?? now()->toDateString();
            $net = 0;
            foreach ($lines as $k => $l) {
                $lines[$k]['price'] = round((float) $l['price'], 4);
                $lines[$k]['total'] = round($lines[$k]['price'] * (float) $l['quantity'], 2);
                $net += $lines[$k]['total'];
            }
            $igv = round($net * (float) $s['igv'] / 100, 2);
            $term = ! empty($data['payment_term_id']) ? DB::table('b2b_payment_terms')->where('company_id', $cid)->where('id', $data['payment_term_id'])->first() : null;
            $saleId = DB::table('sales')->insertGetId([
                'company_id' => $cid, 'user_id' => $userId, 'date' => $date, 'time' => now()->format('H:i:s'), 'Ref' => self::ref($series, $number),
                'is_pos' => 0, 'client_id' => $clientId, 'warehouse_id' => $warehouseId ?: self::warehouse($cid),
                'tax_rate' => (float) $s['igv'], 'TaxNet' => $igv, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => round($net + $igv, 2),
                'paid_amount' => 0, 'payment_statut' => 'unpaid', 'statut' => 'completed', 'notes' => $data['notes'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $l) {
                DB::table('sale_details')->insert([
                    'date' => $date, 'sale_id' => $saleId, 'product_id' => $l['product_id'], 'price' => $l['price'], 'quantity' => $l['quantity'],
                    'total' => $l['total'], 'TaxNet' => 0, 'tax_method' => '2', 'discount' => 0, 'discount_method' => '2',
                    'sale_unit_id' => $l['unit_id'] ?? DB::table('products')->where('id', $l['product_id'])->value('unit_sale_id'),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('b2b_sale_info')->insert([
                'sale_id' => $saleId, 'company_id' => $cid, 'series' => $series, 'number' => $number,
                'purchase_order' => $data['purchase_order'] ?? null,
                'payment_term_id' => $term->id ?? null, 'due_date' => $term ? date('Y-m-d', strtotime($date.' +'.(int) $term->days.' days')) : $date,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::used($cid, $series, $number);

            return $saleId;
        });
    }

    public static function methodId(string $kind): int
    {
        $name = self::METHODS[$kind] ?? self::METHODS['deposit'];
        $id = DB::table('payment_methods')->where('name', $name)->whereNull('deleted_at')->value('id');

        return (int) ($id ?: DB::table('payment_methods')->insertGetId(['name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]));
    }

    /** Recompute a sale's paid amount and status from its payments. */
    public static function refreshSale(int $saleId): void
    {
        $sale = DB::table('sales')->where('id', $saleId)->first(['GrandTotal']);
        $paid = round((float) DB::table('payment_sales')->where('sale_id', $saleId)->whereNull('deleted_at')->sum('montant'), 2);
        $status = $paid <= 0 ? 'unpaid' : ($paid + 0.009 >= (float) $sale->GrandTotal ? 'paid' : 'partial');
        DB::table('sales')->where('id', $saleId)->update(['paid_amount' => $paid, 'payment_statut' => $status, 'updated_at' => now()]);
    }

    /** One collection line on an invoice. */
    public static function addPayment(int $cid, int $saleId, int $userId, array $line): int
    {
        $kind = $line['kind'] ?? 'deposit';
        if (! empty($line['concept_id'])) {
            $kind = DB::table('b2b_concepts')->where('company_id', $cid)->where('id', $line['concept_id'])->value('kind') ?: $kind;
        }
        $pid = DB::table('payment_sales')->insertGetId([
            'user_id' => $userId, 'date' => $line['date'] ?? now()->toDateString(), 'Ref' => 'B2B-'.$saleId.'-'.substr(uniqid(), -5),
            'sale_id' => $saleId, 'montant' => round((float) $line['amount'], 2), 'change' => 0, 'payment_method_id' => self::methodId($kind),
            'notes' => $line['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('b2b_payment_info')->insert([
            'payment_sale_id' => $pid, 'company_id' => $cid, 'concept_id' => $line['concept_id'] ?? null, 'kind' => $kind,
            'document' => $line['document'] ?? null, 'document_date' => $line['document_date'] ?? null, 'percent' => $line['percent'] ?? null,
            'expected' => $line['expected'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $pid;
    }
}
