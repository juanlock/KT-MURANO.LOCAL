<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: company 2 (Ruba Garden Landscape) has thousands of sales
 * with paid_amount > 0 (some as old as 2018), but zero rows in payment_sales
 * -- almost certainly a historical import that set paid_amount directly on
 * the sale without creating the matching payment ledger row that the app's
 * own "Add Payment" flow always creates. This left the Payments -- Sales
 * report permanently empty for this company even though the sales
 * themselves are correctly marked paid/partial.
 *
 * This creates exactly one payment_sales row per affected sale: amount =
 * that sale's own paid_amount, dated to the sale's own date (so historical
 * trend charts stay accurate), method = Cash (id 2, same default the rest
 * of this install uses), user_id = the sale's own creator, no account
 * (matches every other imported/legacy payment_sales row, which also leave
 * account_id null). Ref continues the app's own real numbering sequence
 * (see PaymentSalesController::getNumberOrder() -- same "PP-<n+1>" logic).
 *
 * Idempotent: only touches sales that don't already have a payment_sales row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companyId = 2;
        $cashMethodId = 2;

        $lastRef = DB::table('payment_sales')->latest('id')->value('Ref');
        $nextNumber = 388; // matches getNumberOrder(): last real row was PP-387
        if ($lastRef && preg_match('/-(\d+)$/', (string) $lastRef, $m)) {
            $nextNumber = ((int) $m[1]) + 1;
        }

        $sales = DB::table('sales')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('paid_amount', '>', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('payment_sales')
                    ->whereColumn('payment_sales.sale_id', 'sales.id')
                    ->whereNull('payment_sales.deleted_at');
            })
            ->orderBy('id')
            ->get(['id', 'user_id', 'date', 'paid_amount']);

        $now = now();
        $rows = [];
        foreach ($sales as $sale) {
            $rows[] = [
                'user_id' => $sale->user_id,
                'date' => $sale->date,
                'Ref' => 'PP-'.$nextNumber,
                'sale_id' => $sale->id,
                'account_id' => null,
                'montant' => $sale->paid_amount,
                'change' => 0,
                'payment_method_id' => $cashMethodId,
                'notes' => 'Backfilled: historical paid_amount had no matching payment record.',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $nextNumber++;
        }

        // Batch insert (chunked to stay well under MySQL's max packet size).
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('payment_sales')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('payment_sales')
            ->where('notes', 'Backfilled: historical paid_amount had no matching payment record.')
            ->delete();
    }
};
