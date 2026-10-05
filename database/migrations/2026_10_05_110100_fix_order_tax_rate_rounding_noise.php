<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up to 2026_10_05_110000 on the machine where its first version ran:
 * that version guessed the rate from TaxNet / base, and on small invoices the
 * rounding left rates nobody typed (6.627, 6.628...). A document's rate is
 * either what it stores at 2 decimals, or the x.xx5 that rounds to it
 * (6.625 -> 6.63), whichever its stored tax fits best.
 * (On a fresh machine 2026_10_05_110000 already applies this rule.)
 */
return new class extends Migration
{
    private const DOCS = [
        'sales' => ['sale_details', 'sale_id'],
        'quotations' => ['quotation_details', 'quotation_id'],
    ];

    public function up(): void
    {
        foreach (self::DOCS as $table => [$detailTable, $fk]) {
            $hasDiscountMethod = \Illuminate\Support\Facades\Schema::hasColumn($table, 'discount_Method');
            $rows = DB::table($table)->where('tax_rate', '>', 0)
                ->get(array_merge(['id', 'tax_rate', 'TaxNet', 'discount', 'shipping', 'GrandTotal'], $hasDiscountMethod ? ['discount_Method'] : []));
            foreach ($rows as $r) {
                $sub = (float) DB::table($detailTable)->where($fk, $r->id)->sum('total');
                $discount = $hasDiscountMethod && (string) $r->discount_Method === '1' ? round($sub * (float) $r->discount / 100, 2) : (float) $r->discount;
                // No lines (old imported invoices): the base is what the total says.
                $base = $sub > 0 ? $sub - $discount : (float) $r->GrandTotal - (float) $r->TaxNet - (float) $r->shipping;
                if ($base <= 0) {
                    continue;
                }
                $stored = (float) $r->tax_rate;
                $two = round($stored, 2);
                $five = round($two - 0.005, 3);
                $tax = (float) $r->TaxNet;
                $fits = fn ($rate) => abs(round($base * $rate / 100, 2) - round($tax, 2)) <= 0.011;
                // The x.xx5 whenever the tax fits it: a 2-decimal rate column
                // couldn't store it, so a tie means x.xx5 was typed.
                $best = $fits($five) ? $five : $two;
                if (! $fits($best)) {
                    continue; // neither fits: leave the document alone
                }
                if (abs($best - $stored) > 0.0001) {
                    DB::table($table)->where('id', $r->id)->update(['tax_rate' => $best]);
                }
            }
        }
    }

    public function down(): void
    {
        //
    }
};
