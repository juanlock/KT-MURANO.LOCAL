<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enforce one order per Stripe PaymentIntent (NULLs stay distinct in MySQL,
        // so cash/mobile orders are unaffected). Skip if legacy duplicates exist.
        $dupes = DB::table('online_orders')
            ->whereNotNull('stripe_payment_intent_id')
            ->select('stripe_payment_intent_id')
            ->groupBy('stripe_payment_intent_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($dupes) {
            return;
        }

        Schema::table('online_orders', function (Blueprint $table) {
            $table->unique('stripe_payment_intent_id', 'online_orders_stripe_pi_unique');
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropUnique('online_orders_stripe_pi_unique');
        });
    }
};
