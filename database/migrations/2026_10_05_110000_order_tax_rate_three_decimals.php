<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order tax rates with 3 decimals (NJ sales tax 6.625%). tax_rate was
 * decimal(15,2) on the documents, so 6.625 was stored as 6.63 while the tax
 * amount (TaxNet) had been computed with 6.625 -- the invoice showed a rate
 * that didn't match its own tax.
 *  1. tax_rate -> decimal(15,3) on every document table (drafts' TaxNet too).
 *  2. Rates stored rounded go back to the rate their tax was computed with
 *     (6.63 -> 6.625), only when TaxNet proves it.
 *  3. Sales / quotations whose total was rounded to 2 decimals but whose tax
 *     kept 3: TaxNet is aligned to the stored total (a fraction of a cent),
 *     so tax + subtotal = total in 3-decimal mode too. GrandTotal (what the
 *     customer was charged) is never changed.
 */
return new class extends Migration
{
    private const RATE_TABLES = ['sales', 'quotations', 'sale_returns', 'purchases', 'purchase_returns', 'transfers', 'draft_sales'];

    /** table => [detail table, foreign key] */
    private const DOCS = [
        'sales' => ['sale_details', 'sale_id'],
        'quotations' => ['quotation_details', 'quotation_id'],
    ];

    public function up(): void
    {
        foreach (self::RATE_TABLES as $table) {
            if (Schema::hasColumn($table, 'tax_rate')) {
                Schema::table($table, fn (Blueprint $t) => $t->decimal('tax_rate', 15, 3)->default(0)->nullable()->change());
            }
        }
        foreach (['draft_sales', 'draft_sale_details'] as $table) {
            if (Schema::hasColumn($table, 'TaxNet')) {
                Schema::table($table, fn (Blueprint $t) => $t->decimal('TaxNet', 15, 3)->default(0)->nullable()->change());
            }
        }

        foreach (self::DOCS as $table => [$detailTable, $fk]) {
            $hasMethod = Schema::hasColumn($table, 'tax_Method');
            // Quotations have no discount_Method (always a fixed discount).
            $hasDiscountMethod = Schema::hasColumn($table, 'discount_Method');
            $rows = DB::table($table)->where('tax_rate', '>', 0)
                ->when($hasMethod, fn ($q) => $q->where(fn ($w) => $w->whereNull('tax_Method')->orWhere('tax_Method', '1')))
                ->get(array_merge(['id', 'tax_rate', 'TaxNet', 'discount', 'shipping', 'GrandTotal'], $hasDiscountMethod ? ['discount_Method'] : []));
            foreach ($rows as $r) {
                $sub = (float) DB::table($detailTable)->where($fk, $r->id)->sum('total');
                $discount = $hasDiscountMethod && (string) $r->discount_Method === '1' ? round($sub * (float) $r->discount / 100, 2) : (float) $r->discount;
                // No lines (old imported invoices): the base is what the total says.
                $base = $sub > 0 ? $sub - $discount : (float) $r->GrandTotal - (float) $r->TaxNet - (float) $r->shipping;
                if ($base <= 0) {
                    continue;
                }
                $update = [];

                // 2. The rate the tax was really computed with: a 2-decimal
                //    rounding of x.xx5 (6.625 -> 6.63) goes back to x.xx5 when
                //    the stored tax fits it; otherwise the rate stays.
                $rate = round((float) $r->tax_rate, 2);
                $candidate = round($rate - 0.005, 3);
                // (A tie with the 2-decimal rate means x.xx5 was typed: the
                // column couldn't store it.)
                if ($candidate > 0 && abs((float) $r->tax_rate - $rate) < 0.0001
                    && abs(round($base * $candidate / 100, 2) - round((float) $r->TaxNet, 2)) <= 0.011) {
                    $update['tax_rate'] = $candidate;
                }

                // 3. Tax aligned to the stored (2-decimal) total.
                $aligned = round((float) $r->GrandTotal - $base - (float) $r->shipping, 3);
                if (abs($aligned - (float) $r->TaxNet) >= 0.0005 && abs($aligned - (float) $r->TaxNet) < 0.01) {
                    $update['TaxNet'] = $aligned;
                }

                if ($update) {
                    DB::table($table)->where('id', $r->id)->update($update);
                }
            }
        }
    }

    public function down(): void
    {
        // Not narrowed back: 2 decimals would round 6.625 again.
    }
};
