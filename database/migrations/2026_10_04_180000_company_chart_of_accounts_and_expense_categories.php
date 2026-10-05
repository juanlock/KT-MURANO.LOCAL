<?php

use App\Services\CompanyAccountingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts + expense categories per company (multicompany rule),
 * with Spanish names, and the default set (KT-TOOLS/files/COA-usa2026.csv +
 * "expenses list.csv", App\Support\DefaultAccounting) for every existing
 * company. New companies get it from CompanyProvisioner.
 *
 *  - acc_chart_of_accounts: company_id, name_es. The rows already there have
 *    no company (shared, used by existing journal entries) -- they stay
 *    shared and untouched.
 *  - expense_categories: company_id, name_es and the expense-list columns
 *    (account type, group, typical account, period, approximate amount, most
 *    common in its group, linked account code, order). The existing
 *    categories get the company of the user who created them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_chart_of_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
                $table->unsignedInteger('company_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('acc_chart_of_accounts', 'name_es')) {
                $table->string('name_es', 192)->nullable()->after('name');
            }
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $cols = [
                'company_id' => fn () => $table->unsignedInteger('company_id')->nullable()->after('id')->index(),
                'name_es' => fn () => $table->string('name_es', 192)->nullable()->after('name'),
                'account_type' => fn () => $table->string('account_type', 30)->nullable(),
                'expense_group' => fn () => $table->string('expense_group', 120)->nullable(),
                'expense_group_es' => fn () => $table->string('expense_group_es', 120)->nullable(),
                'typical_account' => fn () => $table->string('typical_account', 192)->nullable(),
                'period' => fn () => $table->string('period', 120)->nullable(),
                'period_es' => fn () => $table->string('period_es', 120)->nullable(),
                'approximate_amount' => fn () => $table->string('approximate_amount', 192)->nullable(),
                'is_common' => fn () => $table->boolean('is_common')->default(false),
                'coa_code' => fn () => $table->string('coa_code', 64)->nullable(),
                'sort_order' => fn () => $table->unsignedSmallInteger('sort_order')->default(0),
            ];
            foreach ($cols as $col => $add) {
                if (! Schema::hasColumn('expense_categories', $col)) {
                    $add();
                }
            }
        });

        // Existing categories: the company of the user who created them.
        DB::table('expense_categories')->whereNull('company_id')->orderBy('id')->get(['id', 'user_id'])
            ->each(function ($c) {
                $cid = $c->user_id ? DB::table('users')->where('id', $c->user_id)->value('company_id') : null;
                if ($cid) {
                    DB::table('expense_categories')->where('id', $c->id)->update(['company_id' => $cid]);
                }
            });

        foreach (DB::table('companies')->whereNull('deleted_at')->pluck('id') as $cid) {
            CompanyAccountingSeeder::seed((int) $cid);
        }
    }

    public function down(): void
    {
        // Company rows (the seeded defaults and anything created per company)
        // would turn into shared duplicates without their company: remove
        // them, keep the original shared accounts and the categories that
        // existed before (seeded ones have sort_order >= 1).
        if (Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
            DB::table('acc_chart_of_accounts')->whereNotNull('company_id')->delete();
        }
        if (Schema::hasColumn('expense_categories', 'sort_order')) {
            DB::table('expense_categories')->where('sort_order', '>', 0)->delete();
        }
        Schema::table('expense_categories', function (Blueprint $table) {
            foreach (['company_id', 'name_es', 'account_type', 'expense_group', 'expense_group_es', 'typical_account', 'period', 'period_es', 'approximate_amount', 'is_common', 'coa_code', 'sort_order'] as $col) {
                if (Schema::hasColumn('expense_categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('acc_chart_of_accounts', function (Blueprint $table) {
            foreach (['company_id', 'name_es'] as $col) {
                if (Schema::hasColumn('acc_chart_of_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
