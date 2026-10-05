<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draft sales:
 *  - sales.is_draft: "Save as draft" on Create / Edit Sale, and recurring
 *    invoices created for review. A draft is a Pending sale (no stock out, no
 *    payment) labelled Draft; finalizing it (or moving it past Pending)
 *    clears the flag.
 *  - sales_contracts.invoice_as_draft: the contract's recurring invoices are
 *    created as drafts (Pending) to review before they're final. On for the
 *    active recurring contracts that exist now (user's request).
 *  - draft_sales.company_id: POS drafts belong to a company (multicompany);
 *    existing ones get their location's company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'is_draft')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->boolean('is_draft')->default(false)->index();
            });
        }
        if (! Schema::hasColumn('sales_contracts', 'invoice_as_draft')) {
            Schema::table('sales_contracts', function (Blueprint $t) {
                $t->boolean('invoice_as_draft')->default(false)->after('billing_enabled');
            });
        }
        DB::table('sales_contracts')->whereNull('deleted_at')->where('status', 'active')->where('billing_enabled', true)
            ->update(['invoice_as_draft' => true]);

        if (! Schema::hasColumn('draft_sales', 'company_id')) {
            Schema::table('draft_sales', function (Blueprint $t) {
                $t->unsignedInteger('company_id')->nullable()->index()->after('id');
            });
        }
        DB::table('draft_sales')->whereNull('company_id')->update([
            'company_id' => DB::raw('(SELECT w.company_id FROM warehouses w WHERE w.id = draft_sales.warehouse_id)'),
        ]);
    }

    public function down(): void
    {
        foreach (['sales' => 'is_draft', 'sales_contracts' => 'invoice_as_draft', 'draft_sales' => 'company_id'] as $table => $col) {
            if (Schema::hasColumn($table, $col)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
