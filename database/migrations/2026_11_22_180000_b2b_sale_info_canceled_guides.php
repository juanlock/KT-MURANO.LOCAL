<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Revertir anulación" (user, 2026-11-22: "¿y si lo anulo por error?"): cancelling an invoice frees its guides, so the
 * guides it had are remembered here to put them back when the cancellation is reverted. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('b2b_sale_info') && ! Schema::hasColumn('b2b_sale_info', 'canceled_guide_ids')) {
            Schema::table('b2b_sale_info', function (Blueprint $t) {
                $t->json('canceled_guide_ids')->nullable()->after('due_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('b2b_sale_info', 'canceled_guide_ids')) {
            Schema::table('b2b_sale_info', fn (Blueprint $t) => $t->dropColumn('canceled_guide_ids'));
        }
    }
};
