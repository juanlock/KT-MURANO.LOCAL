<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_returns had no company_id at all -- matches a gap Billing itself
 * still has (scoped there only indirectly, via whereHas('purchase'/'provider')).
 * Murano's table is currently empty (confirmed both here and in Billing, so
 * no backfill risk), so unlike that indirect pattern this adds the column
 * directly for a real, direct company boundary going forward -- found while
 * auditing PurchasesController/ProvidersController's company scoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_returns', 'company_id')) {
            return;
        }
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->integer('company_id')->default(1)->after('id');
        });
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropForeign('purchase_returns_company_id_foreign');
            $table->dropColumn('company_id');
        });
    }
};
