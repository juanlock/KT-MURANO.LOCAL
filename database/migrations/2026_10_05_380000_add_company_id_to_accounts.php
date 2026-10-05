<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts (bank / cash accounts) belong to a company (multicompany rule):
 * every company was seeing every account. Existing accounts get the company
 * of the documents that use them (expenses / payments, via their location);
 * an account nothing uses yet stays unassigned (null) until edited.
 * App\Models\Account scopes every query to the acting company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'company_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->unsignedInteger('company_id')->nullable()->index()->after('id');
            });
        }
        foreach (DB::table('accounts')->whereNull('company_id')->pluck('id') as $id) {
            $cid = DB::table('expenses as e')->join('warehouses as w', 'w.id', '=', 'e.warehouse_id')->where('e.account_id', $id)->value('w.company_id');
            foreach ([['payment_sales', 'sale_id', 'sales'], ['payment_purchases', 'purchase_id', 'purchases']] as [$pay, $fk, $doc]) {
                if (! $cid && Schema::hasColumn($pay, 'account_id')) {
                    $cid = DB::table("$pay as p")->join("$doc as d", 'd.id', '=', "p.$fk")->where('p.account_id', $id)->value('d.company_id');
                }
            }
            if (! $cid) {
                // Nothing uses it yet: the company whose Defaults point at it.
                $cid = DB::table('settings')->where('expense_default_account_id', $id)->orWhere('default_account_id', $id)->value('company_id');
            }
            if ($cid) {
                DB::table('accounts')->where('id', $id)->update(['company_id' => $cid]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'company_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('company_id');
            });
        }
    }
};
