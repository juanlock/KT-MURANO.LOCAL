<?php

namespace App\Services;

use App\Support\DefaultAccounting;
use App\Support\TranslationKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a company its default chart of accounts and expense categories
 * (App\Support\DefaultAccounting): run for every existing company by the
 * 2026_10_04_180000 migration and for each new one by CompanyProvisioner.
 * Idempotent -- accounts are matched by (company, code), categories by
 * (company, name); anything already there (including a company's own
 * edits) is left alone.
 */
class CompanyAccountingSeeder
{
    /** @return array{accounts: int, categories: int} rows created */
    public static function seed(int $companyId): array
    {
        $created = ['accounts' => 0, 'categories' => 0];
        if (! $companyId || ! Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
            return $created;
        }
        $now = now();

        // Chart of accounts: headers first, then their accounts.
        $ids = DB::table('acc_chart_of_accounts')->where('company_id', $companyId)->pluck('id', 'code')->all();
        foreach ([true, false] as $headers) {
            foreach (DefaultAccounting::ACCOUNTS as [$code, $name, $nameEs]) {
                if (DefaultAccounting::isGroup($code) !== $headers || isset($ids[$code])) {
                    continue;
                }
                $group = DefaultAccounting::groupOf($code);
                $ids[$code] = DB::table('acc_chart_of_accounts')->insertGetId([
                    'company_id' => $companyId,
                    'account_id' => null,
                    'code' => $code,
                    'name' => $name,
                    'name_es' => $nameEs,
                    'type' => DefaultAccounting::typeOf($code),
                    'parent_id' => $group ? ($ids[$group] ?? null) : null,
                    'level' => $headers ? 0 : 1,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created['accounts']++;
            }
        }

        // Expense categories.
        $existing = DB::table('expense_categories')->where('company_id', $companyId)->whereNull('deleted_at')
            ->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        // expense_categories.user_id is NOT NULL: credit the company's admin
        // (else its first user, else the first user of the install).
        $userId = DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderByRaw("type = 'admin' desc")->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');
        $sort = 0;
        foreach (DefaultAccounting::EXPENSE_CATEGORIES as [$name, $nameEs, $type, $group, $typical, $period, $amount, $common, $code]) {
            $sort++;
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            // Spanish name -> translations table (key = the English name).
            TranslationKeys::ensure($name, ['es' => $nameEs]);
            DB::table('expense_categories')->insert([
                'company_id' => $companyId,
                'user_id' => $userId,
                'name' => $name,
                'description' => null,
                'account_type' => $type,
                'expense_group' => $group,
                'expense_group_es' => DefaultAccounting::GROUPS[$group] ?? null,
                'typical_account' => $typical,
                'period' => $period,
                'period_es' => DefaultAccounting::PERIODS[$period] ?? null,
                'approximate_amount' => $amount,
                'is_common' => $common ? 1 : 0,
                'coa_code' => $code,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created['categories']++;
        }

        return $created;
    }
}
