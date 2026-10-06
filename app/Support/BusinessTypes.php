<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a company does (companies.business_type) -- so demo data and the
 * default accounting fit it instead of the generic (IT-services-like) set.
 *
 * Each type adds, on top of App\Support\DefaultAccounting:
 *  - accounts in the company's chart + expense categories for its typical
 *    spending (ensure(): idempotent, real setup -- NOT demo rows, they stay
 *    after "Remove demo data");
 *  - the monthly bills Demo Data -> Regular expenses generates;
 *  - wording for the Demo Data -> Accounting journal entries.
 */
class BusinessTypes
{
    public const DEFAULT = 'general';

    /** key => label (Demo Data dropdown, sorted there by label). */
    public const LABELS = [
        'general' => 'General / other',
        'it_services' => 'IT / technology services',
        'landscaping' => 'Landscaping / lawn care',
        'salon' => 'Hair salon / beauty',
        'barber' => 'Barber shop',
        'retail' => 'Retail store',
        'restaurant' => 'Restaurant / food',
        'professional' => 'Office / professional services (consulting, legal, accounting)',
    ];

    /** Accounts: [code, parent header, English, Spanish]. */
    private const ACCOUNTS = [
        'landscaping' => [
            ['6040', '6000-6999', 'Materials and Supplies', 'Materiales y suministros'],
            ['6050', '6000-6999', 'Subcontractors / Outside Services', 'Subcontratistas / servicios externos'],
            ['7560', '7500-7999', 'Equipment and Tool Expense', 'Herramientas y equipo menor'],
            ['7570', '7500-7999', 'Fuel, Oil and Vehicle Maintenance', 'Combustible, aceite y mantenimiento de vehículos'],
        ],
        'salon' => [
            ['6060', '6000-6999', 'Salon Products and Supplies', 'Productos y suministros de salón'],
            ['7580', '7500-7999', 'Salon Tools and Small Equipment', 'Herramientas y equipo menor de salón'],
            ['8590', '8500-8999', 'Laundry and Cleaning', 'Lavandería y limpieza'],
        ],
        'retail' => [
            ['8595', '8500-8999', 'Packaging and Store Supplies', 'Empaques y suministros de tienda'],
        ],
        'restaurant' => [
            ['6070', '6000-6999', 'Food and Beverage Supplies', 'Alimentos y bebidas'],
            ['7590', '7500-7999', 'Kitchen Supplies and Smallwares', 'Utensilios y suministros de cocina'],
            ['8590', '8500-8999', 'Laundry and Cleaning', 'Lavandería y limpieza'],
        ],
    ];

    /** Categories: [name, Spanish, type, group, typical account, period, common, account code, reference]. */
    private const CATEGORIES = [
        'landscaping' => [
            ['Materials and supplies', 'Materiales y suministros', 'cogs', 'COGS', 'Cost of Goods Sold — Materials and Supplies', 'As purchased / recurring', true, '6040',
                'Items bought for a specific client project or installation: plants, trees, sod, mulch, soil, fertilizer, pavers, stone.'],
            ['Subcontractors and outside services', 'Subcontratistas y servicios externos', 'cogs', 'COGS', 'Cost of Goods Sold — Subcontractors', 'As incurred', false, '6050',
                'An independent contractor doing specialized labor on a job (e.g. a licensed electrician for outdoor lighting, hardscaping sub-crews).'],
            ['Equipment and tool expense', 'Herramientas y equipo menor', 'operating_expense', 'Technology and Equipment Expenses', 'Small Tools and Equipment Expense', 'As needed / one-time', false, '7560',
                'Small tools, safety gear or replacement parts that are not capitalized as fixed assets.'],
            ['Fuel, oil and vehicle maintenance', 'Combustible, aceite y mantenimiento de vehículos', 'operating_expense', 'Travel and Transportation Expenses', 'Fuel and Vehicle Maintenance Expense', 'As incurred / recurring', true, '7570',
                'Gas, oil, truck or trailer parts bought from a supplier or fuel station for the daily routes.'],
        ],
        'salon' => [
            ['Salon products and supplies', 'Productos y suministros de salón', 'cogs', 'COGS', 'Cost of Goods Sold — Salon Products', 'As purchased / recurring', true, '6060',
                'Hair color, developer, shampoo, treatments and retail products used on or sold to clients.'],
            ['Salon tools and small equipment', 'Herramientas y equipo menor de salón', 'operating_expense', 'Technology and Equipment Expenses', 'Small Tools and Equipment Expense', 'As needed / one-time', false, '7580',
                'Scissors, clippers, dryers, brushes and other small tools not capitalized as fixed assets.'],
            ['Laundry and cleaning', 'Lavandería y limpieza', 'operating_expense', 'Facilities and Office Expenses', 'Laundry and Cleaning Expense', 'As incurred / recurring', false, '8590',
                'Towels, capes, laundry service and cleaning / disinfecting supplies.'],
        ],
        'retail' => [
            ['Packaging and store supplies', 'Empaques y suministros de tienda', 'operating_expense', 'Facilities and Office Expenses', 'Packaging and Store Supplies Expense', 'As purchased / recurring', false, '8595',
                'Bags, boxes, tags, receipt paper and other supplies used in the store.'],
        ],
        'restaurant' => [
            ['Food and beverage supplies', 'Alimentos y bebidas', 'cogs', 'COGS', 'Cost of Goods Sold — Food and Beverage', 'Weekly or biweekly', true, '6070',
                'Ingredients, produce, meat, dairy and drinks bought to prepare and sell.'],
            ['Kitchen supplies and smallwares', 'Utensilios y suministros de cocina', 'operating_expense', 'Technology and Equipment Expenses', 'Kitchen Supplies Expense', 'As needed / one-time', false, '7590',
                'Pans, utensils, containers, to-go packaging and other small kitchen items.'],
            ['Laundry and cleaning', 'Lavandería y limpieza', 'operating_expense', 'Facilities and Office Expenses', 'Laundry and Cleaning Expense', 'As incurred / recurring', false, '8590',
                'Linens, uniforms laundry, cleaning and sanitizing supplies.'],
        ],
    ];

    /** Regular monthly bills: [category, base amount, variation 0-1, day of the month]. */
    private const BILLS = [
        'general' => [
            ['Office rent', 1800, 0, 1], ['Software subscriptions', 180, 0.2, 3], ['Internet and telecommunications', 95, 0, 5],
            ['Utilities', 260, 0.35, 12], ['Insurance', 240, 0, 15], ['Office supplies', 110, 0.6, 18], ['Advertising', 450, 0.5, 20],
            ['Accounting services', 350, 0, 25], ['Credit card processing fees', 140, 0.4, 28], ['Bank fees', 28, 0.4, 28],
        ],
        'landscaping' => [
            ['Office rent', 1500, 0, 1], ['Software subscriptions', 60, 0, 3], ['Internet and telecommunications', 95, 0, 5],
            ['Fuel, oil and vehicle maintenance', 650, 0.35, 8], ['Materials and supplies', 2400, 0.5, 10], ['Utilities', 180, 0.3, 12],
            ['Equipment and tool expense', 180, 0.7, 14], ['Insurance', 380, 0, 15], ['Advertising', 300, 0.5, 20],
            ['Subcontractors and outside services', 1200, 0.6, 22], ['Accounting services', 300, 0, 25],
            ['Credit card processing fees', 120, 0.4, 28], ['Bank fees', 25, 0.4, 28],
        ],
        'salon' => [
            ['Office rent', 2200, 0, 1], ['Software subscriptions', 120, 0, 3], ['Internet and telecommunications', 80, 0, 5],
            ['Salon products and supplies', 900, 0.4, 9], ['Utilities', 300, 0.3, 12], ['Laundry and cleaning', 120, 0.3, 14],
            ['Insurance', 180, 0, 15], ['Salon tools and small equipment', 90, 0.7, 17], ['Advertising', 250, 0.5, 20],
            ['Accounting services', 250, 0, 25], ['Credit card processing fees', 220, 0.4, 28], ['Bank fees', 25, 0.4, 28],
        ],
        'retail' => [
            ['Office rent', 2500, 0, 1], ['Software subscriptions', 150, 0, 3], ['Internet and telecommunications', 90, 0, 5],
            ['Inventory purchases', 4000, 0.4, 6], ['Utilities', 350, 0.3, 12], ['Packaging and store supplies', 120, 0.5, 14],
            ['Insurance', 220, 0, 15], ['Shipping and delivery', 200, 0.5, 17], ['Advertising', 400, 0.5, 20],
            ['Accounting services', 300, 0, 25], ['Credit card processing fees', 380, 0.4, 28], ['Bank fees', 30, 0.4, 28],
        ],
        'restaurant' => [
            ['Office rent', 3500, 0, 1], ['Software subscriptions', 160, 0, 3], ['Food and beverage supplies', 6500, 0.3, 4],
            ['Internet and telecommunications', 90, 0, 5], ['Utilities', 900, 0.3, 12], ['Kitchen supplies and smallwares', 200, 0.6, 13],
            ['Laundry and cleaning', 250, 0.3, 14], ['Insurance', 400, 0, 15], ['Repairs and maintenance', 250, 0.7, 18],
            ['Advertising', 300, 0.5, 20], ['Accounting services', 400, 0, 25], ['Credit card processing fees', 650, 0.3, 28], ['Bank fees', 35, 0.4, 28],
        ],
        'professional' => [
            ['Office rent', 1800, 0, 1], ['Software subscriptions', 300, 0.2, 3], ['Internet and telecommunications', 95, 0, 5],
            ['Utilities', 200, 0.3, 12], ['Insurance', 300, 0, 15], ['Office supplies', 90, 0.6, 18], ['Advertising', 250, 0.5, 20],
            ['Dues and memberships', 60, 0, 22], ['Accounting services', 350, 0, 25], ['Credit card processing fees', 90, 0.4, 28], ['Bank fees', 25, 0.4, 28],
        ],
    ];

    /** Accounting demo wording: [quarterly invoice, quarterly supplies purchase, supplies account code]. */
    private const JOURNAL = [
        'general' => ['Invoice to corporate customer (on credit)', 'Printer paper and toner', null],
        'it_services' => ['Managed IT services invoice (on credit)', 'Printer paper and toner', null],
        'landscaping' => ['Landscape maintenance contract invoice (on credit)', 'Mulch, soil and plants for client jobs', '6040'],
        'salon' => ['Corporate / event styling invoice (on credit)', 'Hair color and salon products', '6060'],
        'retail' => ['Wholesale order to business customer (on credit)', 'Bags, tags and store supplies', '8595'],
        'restaurant' => ['Catering invoice (on credit)', 'Weekly produce and dry goods order', '6070'],
        'professional' => ['Consulting engagement invoice (on credit)', 'Printer paper and toner', null],
    ];

    /** Types that share another type's accounts / categories / bills / journal wording. */
    private const SAME_AS = ['barber' => 'salon'];

    private static function base(string $type): string
    {
        return self::SAME_AS[$type] ?? $type;
    }

    public static function valid(?string $type): string
    {
        return isset(self::LABELS[$type ?? '']) ? $type : self::DEFAULT;
    }

    /** The company's business type (companies.business_type), else general. */
    public static function of(int $companyId): string
    {
        $type = Schema::hasColumn('companies', 'business_type')
            ? DB::table('companies')->where('id', $companyId)->value('business_type')
            : null;

        return self::valid($type);
    }

    public static function set(int $companyId, string $type): void
    {
        if (Schema::hasColumn('companies', 'business_type')) {
            DB::table('companies')->where('id', $companyId)->update(['business_type' => self::valid($type)]);
        }
    }

    public static function bills(string $type): array
    {
        return self::BILLS[self::base($type)] ?? self::BILLS[self::DEFAULT];
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    public static function journal(string $type): array
    {
        return self::JOURNAL[self::base($type)] ?? self::JOURNAL[self::DEFAULT];
    }

    /**
     * The type's accounts + expense categories for the company (on top of the
     * default set). Idempotent: accounts by code, categories by name.
     *
     * @return array{accounts: int, categories: int} rows created
     */
    public static function ensure(int $companyId, string $type): array
    {
        $created = ['accounts' => 0, 'categories' => 0];
        if (! $companyId || ! Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
            return $created;
        }
        $type = self::base($type);
        $now = now();
        $ids = DB::table('acc_chart_of_accounts')->where('company_id', $companyId)->pluck('id', 'code')->all();
        foreach (self::ACCOUNTS[$type] ?? [] as [$code, $parent, $name, $nameEs]) {
            if (isset($ids[$code])) {
                continue;
            }
            $ids[$code] = DB::table('acc_chart_of_accounts')->insertGetId([
                'company_id' => $companyId, 'account_id' => null, 'code' => $code, 'name' => $name, 'name_es' => $nameEs,
                'type' => DefaultAccounting::typeOf($code), 'parent_id' => $ids[$parent] ?? null, 'level' => 1, 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $created['accounts']++;
        }

        $existing = DB::table('expense_categories')->where('company_id', $companyId)->whereNull('deleted_at')
            ->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $userId = DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderByRaw("type = 'admin' desc")->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');
        $sort = (int) DB::table('expense_categories')->where('company_id', $companyId)->max('sort_order');
        foreach (self::CATEGORIES[$type] ?? [] as [$name, $nameEs, $acType, $group, $typical, $period, $common, $code, $reference]) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            TranslationKeys::ensure($name, ['es' => $nameEs]);
            DB::table('expense_categories')->insert([
                'company_id' => $companyId, 'user_id' => $userId, 'name' => $name, 'reference' => $reference, 'description' => null,
                'account_type' => $acType, 'expense_group' => $group, 'expense_group_es' => DefaultAccounting::GROUPS[$group] ?? null,
                'typical_account' => $typical, 'period' => $period, 'period_es' => DefaultAccounting::PERIODS[$period] ?? null,
                'approximate_amount' => null, 'is_common' => $common ? 1 : 0, 'coa_code' => $code,
                'sort_order' => ++$sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $existing[] = mb_strtolower($name);
            $created['categories']++;
        }

        return $created;
    }
}
