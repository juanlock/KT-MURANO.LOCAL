<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * 2026_09_27_140000_backfill_payment_sales_for_company_2 created one payment
 * per historical company-2 sale (3,339 rows, PP-...) with the internal note
 * "Backfilled: historical paid_amount had no matching payment record." --
 * and the customer invoice PDF prints every payment's notes under "Payment
 * Notes" (SalesController, payment_note), so that internal text reached
 * customers (e.g. INV_3366 / PP-3639). Clear it; the payments themselves stay.
 * The backfill is still documented in that migration and kt_logs #148.
 *
 * Not reversible (the rows carry no other marker), and the backfill
 * migration's own down() -- which deleted by this note -- no longer finds them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_sales')
            ->where('notes', 'Backfilled: historical paid_amount had no matching payment record.')
            ->update(['notes' => null]);
    }

    public function down(): void
    {
        // Intentionally empty -- see above.
    }
};
