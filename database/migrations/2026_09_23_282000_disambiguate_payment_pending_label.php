<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 'PaymentPending' (legacy payment_statut='pending') and 'Unpaid'
 * (payment_statut='unpaid') translated to the exact same Spanish text
 * ("No pagado") and near-identical English ("UnPaid" vs "Unpaid") -- a
 * company with both real values in its data would show a literal duplicate
 * in the Payment Status droplist/tag. Giving 'PaymentPending' its own
 * distinct wording instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('translations')->where('key', 'PaymentPending')->update([
            'en' => 'Payment Pending',
            'es' => 'Pago pendiente',
        ]);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'PaymentPending')->update([
            'en' => 'UnPaid',
            'es' => 'No pagado',
        ]);
    }
};
