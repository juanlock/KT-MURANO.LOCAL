<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Contracts get a real ORDER TAX (sales_contracts.order_tax_rate), so
 * each recurring invoice carries it like any sale (sales.tax_rate / TaxNet)
 * and Edit Sale shows it. Until now the sale form's Recurring mode folded the
 * order tax into every line as an item tax, so those invoices opened with
 * Order Tax 0% and 6.625% on each line.
 *
 * Repair, per company: a contract whose lines ALL carry the company's default
 * tax (exclusive) gets it as its order tax and its lines lose it; the
 * invoices it already made are converted the same way -- lines without tax,
 * sale tax_rate = the rate, TaxNet = what the lines held -- keeping each
 * invoice's GrandTotal exactly as charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_contracts', 'order_tax_rate')) {
            Schema::table('sales_contracts', function (Blueprint $t) {
                $t->decimal('order_tax_rate', 8, 3)->default(0)->after('value');
            });
        }

        $defaults = DB::table('settings')->whereNull('deleted_at')->pluck('default_tax', 'company_id');
        foreach (DB::table('sales_contracts')->whereNull('deleted_at')->where('order_tax_rate', 0)->get(['id', 'company_id']) as $c) {
            $rate = (float) ($defaults[$c->company_id] ?? 0);
            if ($rate <= 0) {
                continue;
            }
            $items = DB::table('sales_contract_items')->where('sales_contract_id', $c->id)->get(['id', 'tax_percent', 'tax_method']);
            $folded = $items->isNotEmpty() && $items->every(fn ($i) => abs((float) $i->tax_percent - $rate) < 0.0005 && (string) ($i->tax_method ?: '1') === '1');
            if (! $folded) {
                continue;
            }
            DB::table('sales_contract_items')->where('sales_contract_id', $c->id)->update(['tax_percent' => 0]);
            DB::table('sales_contracts')->where('id', $c->id)->update(['order_tax_rate' => $rate]);

            // Its invoices: line tax -> order tax, total unchanged.
            foreach (DB::table('sales_contract_invoices')->where('sales_contract_id', $c->id)->pluck('sale_id') as $saleId) {
                $sale = DB::table('sales')->where('id', $saleId)->whereNull('deleted_at')->first(['id', 'tax_rate', 'TaxNet', 'shipping', 'discount', 'GrandTotal']);
                if (! $sale || (float) $sale->tax_rate > 0 || (float) $sale->discount > 0) {
                    continue;
                }
                $lines = DB::table('sale_details')->where('sale_id', $saleId)->get(['id', 'quantity', 'price', 'discount', 'TaxNet', 'tax_method', 'total']);
                if ($lines->isEmpty() || ! $lines->every(fn ($l) => abs((float) $l->TaxNet - $rate) < 0.0005 && (string) ($l->tax_method ?: '1') === '1' && (float) $l->discount == 0)) {
                    continue;
                }
                // The sale still adds up as saved (untouched since it was made).
                if (abs($lines->sum('total') + (float) $sale->shipping - (float) $sale->GrandTotal) > 0.011) {
                    continue;
                }
                $net = 0.0;
                foreach ($lines as $l) {
                    $lineNet = round((float) $l->quantity * (float) $l->price, 3);
                    $net += $lineNet;
                    DB::table('sale_details')->where('id', $l->id)->update(['TaxNet' => 0, 'total' => $lineNet]);
                }
                DB::table('sales')->where('id', $saleId)->update([
                    'tax_rate' => $rate,
                    'tax_Method' => '1',
                    'TaxNet' => round((float) $sale->GrandTotal - $net - (float) $sale->shipping, 3),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_contracts', 'order_tax_rate')) {
            Schema::table('sales_contracts', fn (Blueprint $t) => $t->dropColumn('order_tax_rate'));
        }
    }
};
