<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ruba Garden Landscape (company 2) is a landscaping company: the default
 * (generic) expense list has no categories for its supplier spending. Adds
 * them -- with their own accounts in company 2's chart -- and explains
 * "Office supplies" as shop or office supplies. Company 2 only; idempotent
 * (accounts by code, categories by name); nothing existing is renamed.
 */
return new class extends Migration
{
    private const COMPANY = 2;

    /** [code, parent header, English, Spanish] */
    private const ACCOUNTS = [
        ['6040', '6000-6999', 'Materials and Supplies', 'Materiales y suministros'],
        ['6050', '6000-6999', 'Subcontractors / Outside Services', 'Subcontratistas / servicios externos'],
        ['7560', '7500-7999', 'Equipment and Tool Expense', 'Herramientas y equipo menor'],
        ['7570', '7500-7999', 'Fuel, Oil and Vehicle Maintenance', 'Combustible, aceite y mantenimiento de vehículos'],
    ];

    /** [name, Spanish, type, group, typical account, period, common, code, reference] */
    private const CATEGORIES = [
        ['Materials and supplies', 'Materiales y suministros', 'cogs', 'COGS', 'Cost of Goods Sold — Materials and Supplies', 'As purchased / recurring', true, '6040',
            'Items bought for a specific client project or installation: plants, trees, sod, mulch, soil, fertilizer, pavers, stone.'],
        ['Subcontractors and outside services', 'Subcontratistas y servicios externos', 'cogs', 'COGS', 'Cost of Goods Sold — Subcontractors', 'As incurred', false, '6050',
            'An independent contractor doing specialized labor on a job (e.g. a licensed electrician for outdoor lighting, hardscaping sub-crews).'],
        ['Equipment and tool expense', 'Herramientas y equipo menor', 'operating_expense', 'Technology and Equipment Expenses', 'Small Tools and Equipment Expense', 'As needed / one-time', false, '7560',
            'Small tools, safety gear or replacement parts that are not capitalized as fixed assets.'],
        ['Fuel, oil and vehicle maintenance', 'Combustible, aceite y mantenimiento de vehículos', 'operating_expense', 'Travel and Transportation Expenses', 'Fuel and Vehicle Maintenance Expense', 'As incurred / recurring', true, '7570',
            'Gas, oil, truck or trailer parts bought from a supplier or fuel station for the daily routes.'],
    ];

    private const OFFICE_SUPPLIES_REFERENCE = 'Shop or office supplies: general administrative or shop upkeep supplies not tied to a specific customer job.';

    public function up(): void
    {
        if (! DB::table('companies')->where('id', self::COMPANY)->exists()
            || ! Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
            return;
        }
        $now = now();
        $cid = self::COMPANY;

        // Accounts in company 2's chart.
        $ids = DB::table('acc_chart_of_accounts')->where('company_id', $cid)->pluck('id', 'code')->all();
        foreach (self::ACCOUNTS as [$code, $parent, $name, $nameEs]) {
            if (isset($ids[$code])) {
                continue;
            }
            $ids[$code] = DB::table('acc_chart_of_accounts')->insertGetId([
                'company_id' => $cid, 'account_id' => null, 'code' => $code, 'name' => $name, 'name_es' => $nameEs,
                'type' => 'expense', 'parent_id' => $ids[$parent] ?? null, 'level' => 1, 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Expense categories.
        $existing = DB::table('expense_categories')->where('company_id', $cid)->whereNull('deleted_at')
            ->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $userId = DB::table('users')->where('company_id', $cid)->whereNull('deleted_at')
            ->orderByRaw("type = 'admin' desc")->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');
        $sort = (int) DB::table('expense_categories')->where('company_id', $cid)->max('sort_order');
        foreach (self::CATEGORIES as [$name, $nameEs, $type, $group, $typical, $period, $common, $code, $reference]) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            TranslationKeys::ensure($name, ['es' => $nameEs]);
            DB::table('expense_categories')->insert([
                'company_id' => $cid, 'user_id' => $userId, 'name' => $name, 'reference' => $reference, 'description' => null,
                'account_type' => $type, 'expense_group' => $group,
                'expense_group_es' => \App\Support\DefaultAccounting::GROUPS[$group] ?? null,
                'typical_account' => $typical, 'period' => $period,
                'period_es' => \App\Support\DefaultAccounting::PERIODS[$period] ?? null,
                'approximate_amount' => null, 'is_common' => $common ? 1 : 0, 'coa_code' => $code,
                'sort_order' => ++$sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // "Office supplies" = shop or office supplies (only when it has no reference yet).
        DB::table('expense_categories')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('name', 'Office supplies')->where(fn ($q) => $q->whereNull('reference')->orWhere('reference', ''))
            ->update(['reference' => self::OFFICE_SUPPLIES_REFERENCE, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $cid = self::COMPANY;
        $names = array_column(self::CATEGORIES, 0);
        DB::table('expense_categories')->where('company_id', $cid)->whereIn('name', $names)
            ->whereNotIn('id', DB::table('expenses')->whereNotNull('expense_category_id')->select('expense_category_id'))
            ->delete();
        DB::table('expense_categories')->where('company_id', $cid)->where('name', 'Office supplies')
            ->where('reference', self::OFFICE_SUPPLIES_REFERENCE)->update(['reference' => null]);
        DB::table('acc_chart_of_accounts')->where('company_id', $cid)->whereIn('code', array_column(self::ACCOUNTS, 0))
            ->whereNotIn('code', DB::table('expense_categories')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('coa_code')->select('coa_code'))
            ->delete();
    }
};
