<?php

namespace App\Support;

/**
 * Default chart of accounts and expense categories every company gets
 * (App\Services\CompanyAccountingSeeder). Source: KT-TOOLS/files/
 * COA-usa2026.csv (English + Spanish) and "expenses list.csv" (English; the
 * Spanish names are translations made for Murano).
 *
 * Account codes: "1000-1099" style rows are group headers (level 0); every
 * account under them is a child (level 1). Types by first digit: 1 asset,
 * 2 liability, 3 equity, 4-5 income, 6-8 expense.
 */
class DefaultAccounting
{
    /** [code, English, Spanish] in chart order. The last 3 are Murano's own (see POSTING_CODES). */
    public const ACCOUNTS = [
        ['1000-1099', 'Current Assets', 'Activos Corrientes'],
        ['1010', 'Cash', 'Efectivo'],
        ['1020', 'Savings Account', 'Cuenta bancaria de ahorros'],
        ['1030', 'Accounts Receivable', 'Cuentas por cobrar'],
        ['1040', 'Billed but Unpaid Services', 'Facturas pendientes de cobro'],
        ['1050', 'Inventory (Hardware/Software Licenses/Supplies)', 'Inventario (hardware/licencias/suministros)'],
        ['1060', 'Prepaid Expenses', 'Gastos pagados por anticipado'],
        ['1100-1999', 'Fixed Assets', 'Activos Fijos'],
        ['1110', 'Computer Equipment', 'Equipo de computación'],
        ['1120', 'Networking & Server Hardware', 'Equipo de redes y servidores'],
        ['1130', 'Office Furniture & Equipment', 'Mobiliario y equipo de oficina'],
        ['1140', 'Vehicles', 'Vehículos'],
        ['1500', 'Accumulated Depreciation', 'Depreciación acumulada (contra-cuenta)'],
        ['2000-2099', 'Current Liabilities', 'Pasivos Corrientes'],
        ['2010', 'Accounts Payable', 'Cuentas por pagar'],
        ['2020', 'Accrued Expenses', 'Gastos acumulados'],
        ['2030', 'Payroll Taxes Payable', 'Impuestos sobre nómina por pagar'],
        ['2040', 'Sales Tax Payable', 'Impuestos sobre ventas por pagar'],
        ['2050', 'Deferred Revenue', 'Ingresos diferidos'],
        ['2100-2999', 'Long-Term Liabilities', 'Pasivos a Largo Plazo'],
        ['2110', 'Long-Term Loans', 'Préstamos a largo plazo'],
        ['3000-3099', 'Equity', 'Patrimonio'],
        ['3010', 'Owner’s Capital / Share Capital', 'Capital del propietario / capital social'],
        ['3020', 'Retained Earnings', 'Utilidades retenidas'],
        ['3030', 'Owner’s Draws / Dividends', 'Retiros del propietario / dividendos'],
        ['4000-4999', 'IT Service Revenue', 'Ingresos por servicios de TI'],
        ['4010', 'Remote Technical Support Revenue', 'Soporte técnico remoto'],
        ['4020', 'On-Site Technical Support Revenue', 'Soporte técnico en sitio'],
        ['4030', 'Managed Services Revenue', 'Servicios gestionados (mensual/contratos)'],
        ['4040', 'Installation & Configuration Revenue', 'Instalación y configuración'],
        ['4050', 'IT Consulting Revenue', 'Consultoría de TI'],
        ['4060', 'Data Recovery Services Revenue', 'Recuperación de datos'],
        ['4070', 'Cybersecurity Services Revenue', 'Seguridad informática'],
        ['4080', 'Hardware Repair Revenue', 'Reparación de hardware'],
        ['4990', 'Sales Returns & Allowances', 'Devoluciones y descuentos sobre ventas'],
        ['5000-5999', 'Sales Revenue (if applicable)', 'Ingresos por ventas (si aplica)'],
        ['5000', 'Sales Revenue', 'Ingresos por ventas'],
        ['5010', 'Hardware Sales', 'Venta de hardware'],
        ['5020', 'Software License Sales', 'Venta de licencias de software'],
        ['5030', 'IT Supplies Sales', 'Venta de suministros de TI'],
        ['6000-6999', 'Cost of Goods Sold (COGS)', 'Costo de Bienes Vendidos (COGS)'],
        ['6010', 'Cost of Hardware Sold', 'Costo de hardware vendido'],
        ['6020', 'Cost of Software Licenses Sold', 'Costo de licencias vendidas'],
        ['6030', 'Cost of IT Supplies Sold', 'Costo de suministros vendidos'],
        ['6990', 'Purchase Returns', 'Devoluciones sobre compras'],
        ['7000-7499', 'Personnel Expenses', 'Gastos de personal'],
        ['7010', 'Salaries & Wages', 'Salarios y sueldos'],
        ['7020', 'Payroll Taxes', 'Impuestos sobre nómina'],
        ['7030', 'Employee Benefits', 'Beneficios para empleados'],
        ['7040', 'Commissions (if applicable)', 'Comisiones (si aplica)'],
        ['7500-7999', 'IT Operations Expenses', 'Gastos operativos de TI'],
        ['7510', 'Cost of Support Tools & Software', 'Herramientas de soporte y software'],
        ['7520', 'Cost of Internal Software Licenses', 'Licencias internas de software'],
        ['7530', 'Internal Equipment Maintenance', 'Mantenimiento de equipo interno'],
        ['7540', 'Travel Expenses', 'Viáticos / desplazamiento'],
        ['7550', 'Equipment Depreciation Expense', 'Depreciación de equipo'],
        ['8000-8499', 'Marketing & Sales Expenses', 'Marketing y ventas'],
        ['8010', 'Marketing & Advertising', 'Marketing y publicidad'],
        ['8020', 'Customer Acquisition Costs', 'Costos de adquisición de clientes'],
        ['8030', 'Credit Card Processing Fees', 'Comisiones por procesamiento de tarjetas'],
        ['8500-8999', 'General & Administrative Expenses', 'Generales y administrativos'],
        ['8510', 'Office Rent', 'Alquiler de oficina'],
        ['8520', 'Utilities', 'Servicios públicos'],
        ['8530', 'Insurance (Liability, E&O)', 'Seguros (liability / E&O)'],
        ['8540', 'Professional Fees (Accounting/Legal)', 'Honorarios profesionales (contab/legal)'],
        ['8550', 'Licenses & Permits', 'Licencias y permisos'],
        ['8560', 'Office Supplies', 'Papelería/suministros de oficina'],
        ['8570', 'Travel & Entertainment', 'Viaje y representación'],
        ['8580', 'Miscellaneous Expenses', 'Gastos varios'],
    ];

    /**
     * Automatic postings (AccountingV2\AccountingHelper) -> this chart:
     * the old fixed code each posting used => the company's account code.
     * 4990 (sales returns), 5000 (sales revenue) and 6990 (purchase returns)
     * aren't in the source file -- added so every posting has a home.
     */
    public const POSTING_CODES = [
        '1000-CASH' => '1010',
        '1100-AR' => '1030',
        '1200-INV' => '1050',
        '2100-AP' => '2010',
        '4000-REV' => '5000',
        '4010-RET' => '4990',
        '5010-PRET' => '6990',
        '3100' => '3020', // retained earnings (deposits, register openings)
        'EXP' => '8580',  // an expense whose category has no account
    ];

    /** Expense groups: English => Spanish. */
    public const GROUPS = [
        'COGS' => 'Costo de bienes vendidos (COGS)',
        'Payroll and Employee Expenses' => 'Nómina y gastos de personal',
        'Facilities and Office Expenses' => 'Instalaciones y gastos de oficina',
        'Technology and Equipment Expenses' => 'Tecnología y equipo',
        'Professional and Administrative Services' => 'Servicios profesionales y administrativos',
        'Sales and Marketing Expenses' => 'Ventas y marketing',
        'Travel and Transportation Expenses' => 'Viajes y transporte',
        'Financial and Banking Expenses' => 'Gastos financieros y bancarios',
        'General Expenses' => 'Gastos generales',
        'Other Expenses' => 'Otros gastos',
        'Fixed Assets' => 'Activos fijos',
    ];

    /** Periods: English => Spanish. */
    public const PERIODS = [
        'As purchased / recurring' => 'Al comprar / recurrente',
        'Weekly or biweekly' => 'Semanal o quincenal',
        'Monthly' => 'Mensual',
        'Each payroll / quarterly' => 'Cada nómina / trimestral',
        'As incurred / one-time' => 'Al incurrir / única vez',
        'As incurred / annual' => 'Al incurrir / anual',
        'As needed / one-time' => 'Según necesidad / única vez',
        'As incurred' => 'Al incurrir',
        'As incurred / recurring' => 'Al incurrir / recurrente',
        'Monthly or annual' => 'Mensual o anual',
        'As incurred / monthly' => 'Al incurrir / mensual',
        'Monthly, quarterly or annual' => 'Mensual, trimestral o anual',
        'Annual or one-time' => 'Anual o única vez',
        'Monthly or as incurred' => 'Mensual o al incurrir',
        'One-time / as incurred' => 'Única vez / al incurrir',
        'Per transaction / monthly' => 'Por transacción / mensual',
        'Monthly or annual adjustment' => 'Ajuste mensual o anual',
        'One-time / as purchased' => 'Única vez / al comprar',
    ];

    /**
     * Expense categories: [English, Spanish, account type, group, typical
     * account, period, approximate amount (USD), most commonly used in its
     * group, account code]. Account types: cogs, operating_expense,
     * other_expense, asset. "Computer equipment" appears twice in the source
     * (Technology and Fixed Assets) -- kept once, under Fixed Assets.
     */
    public const EXPENSE_CATEGORIES = [
        ['Inventory purchases', 'Compras de inventario', 'cogs', 'COGS', 'Cost of Goods Sold — Inventory', 'As purchased / recurring', 'Variable; commonly 20%–60% of sales', true, '6010'],
        ['Direct labor', 'Mano de obra directa', 'cogs', 'COGS', 'Cost of Goods Sold — Direct Labor', 'Weekly or biweekly', 'Variable; commonly 10%–30% of sales', false, '7010'],
        ['Manufacturing overhead', 'Gastos indirectos de fabricación', 'cogs', 'COGS', 'Cost of Goods Sold — Manufacturing Overhead', 'Monthly', 'Variable; commonly 5%–20% of sales', false, '6030'],
        ['Salaries and wages', 'Sueldos y salarios', 'operating_expense', 'Payroll and Employee Expenses', 'Payroll Expense', 'Weekly or biweekly', 'USD 3,000–USD 15,000+ per employee/month', true, '7010'],
        ['Employer payroll taxes', 'Impuestos patronales sobre nómina', 'operating_expense', 'Payroll and Employee Expenses', 'Payroll Tax Expense', 'Each payroll / quarterly', 'Approximately 7%–12% of payroll', false, '7020'],
        ['Employee benefits', 'Beneficios para empleados', 'operating_expense', 'Payroll and Employee Expenses', 'Employee Benefits Expense', 'Monthly', 'Approximately USD 500–USD 2,500 per employee/month', false, '7030'],
        ['Recruiting', 'Reclutamiento', 'operating_expense', 'Payroll and Employee Expenses', 'Recruiting Expense', 'As incurred / one-time', 'USD 500–USD 15,000 per hire', false, '8580'],
        ['Training and education', 'Capacitación y educación', 'operating_expense', 'Payroll and Employee Expenses', 'Training and Education Expense', 'As incurred / annual', 'USD 500–USD 5,000 per employee/year', false, '8580'],
        ['Uniforms and protective equipment', 'Uniformes y equipo de protección', 'operating_expense', 'Payroll and Employee Expenses', 'Uniforms and Safety Equipment Expense', 'As needed / one-time', 'USD 50–USD 1,500 per employee', false, '7030'],
        ['Office rent', 'Alquiler de oficina', 'operating_expense', 'Facilities and Office Expenses', 'Rent Expense', 'Monthly', 'USD 1,500–USD 25,000+ per month', true, '8510'],
        ['Utilities', 'Servicios públicos', 'operating_expense', 'Facilities and Office Expenses', 'Utilities Expense', 'Monthly', 'USD 200–USD 5,000 per month', false, '8520'],
        ['Internet and telecommunications', 'Internet y telecomunicaciones', 'operating_expense', 'Facilities and Office Expenses', 'Telephone and Internet Expense', 'Monthly', 'USD 100–USD 2,000 per month', false, '8520'],
        ['Office supplies', 'Suministros de oficina', 'operating_expense', 'Facilities and Office Expenses', 'Office Supplies Expense', 'As purchased / recurring', 'USD 50–USD 2,000 per month', false, '8560'],
        ['Repairs and maintenance', 'Reparaciones y mantenimiento', 'operating_expense', 'Facilities and Office Expenses', 'Repairs and Maintenance Expense', 'As incurred', 'USD 100–USD 10,000 per occurrence', false, '7530'],
        ['Shipping and delivery', 'Envíos y entregas', 'operating_expense', 'Facilities and Office Expenses', 'Shipping and Delivery Expense', 'As incurred / recurring', 'USD 100–USD 10,000+ per month', false, '8580'],
        ['Software subscriptions', 'Suscripciones de software', 'operating_expense', 'Technology and Equipment Expenses', 'Software and SaaS Expense', 'Monthly or annual', 'USD 100–USD 10,000+ per month', true, '7520'],
        ['Depreciation', 'Depreciación', 'operating_expense', 'Technology and Equipment Expenses', 'Depreciation Expense', 'Monthly or annual', 'Approximately 10%–33% of asset cost/year', false, '7550'],
        ['Professional services', 'Servicios profesionales', 'operating_expense', 'Professional and Administrative Services', 'Professional Fees Expense', 'As incurred / monthly', 'USD 500–USD 15,000+ per month', true, '8540'],
        ['Legal services', 'Servicios legales', 'operating_expense', 'Professional and Administrative Services', 'Legal Expense', 'As incurred', 'USD 500–USD 25,000+ per matter', false, '8540'],
        ['Accounting services', 'Servicios de contabilidad', 'operating_expense', 'Professional and Administrative Services', 'Accounting and Tax Expense', 'Monthly, quarterly or annual', 'USD 300–USD 5,000+ per month', false, '8540'],
        ['Business licenses and permits', 'Licencias y permisos comerciales', 'operating_expense', 'Professional and Administrative Services', 'Licenses and Permits Expense', 'Annual or one-time', 'USD 50–USD 5,000 per year', false, '8550'],
        ['Dues and memberships', 'Cuotas y membresías', 'operating_expense', 'Professional and Administrative Services', 'Dues and Subscriptions Expense', 'Monthly or annual', 'USD 100–USD 5,000 per year', false, '8580'],
        ['Insurance', 'Seguros', 'operating_expense', 'Professional and Administrative Services', 'Insurance Expense', 'Monthly, quarterly or annual', 'USD 500–USD 20,000+ per year', false, '8530'],
        ['Advertising', 'Publicidad', 'operating_expense', 'Sales and Marketing Expenses', 'Advertising Expense', 'As incurred / recurring', 'USD 500–USD 50,000+ per month', true, '8010'],
        ['Marketing', 'Marketing', 'operating_expense', 'Sales and Marketing Expenses', 'Marketing Expense', 'Monthly or as incurred', 'USD 1,000–USD 50,000+ per month', false, '8010'],
        ['Charitable donations', 'Donaciones benéficas', 'operating_expense', 'Sales and Marketing Expenses', 'Charitable Contributions Expense', 'One-time / as incurred', 'USD 100–USD 10,000 per contribution', false, '8580'],
        ['Travel', 'Viajes', 'operating_expense', 'Travel and Transportation Expenses', 'Travel Expense', 'As incurred', 'USD 500–USD 5,000 per trip', true, '7540'],
        ['Meals and entertainment', 'Comidas y entretenimiento', 'operating_expense', 'Travel and Transportation Expenses', 'Meals and Entertainment Expense', 'As incurred', 'USD 25–USD 200 per person/event', false, '8570'],
        ['Automobile and mileage', 'Automóvil y kilometraje', 'operating_expense', 'Travel and Transportation Expenses', 'Vehicle or Mileage Expense', 'As incurred / monthly', 'USD 200–USD 3,000 per vehicle/month', false, '7540'],
        ['Bank fees', 'Comisiones bancarias', 'operating_expense', 'Financial and Banking Expenses', 'Bank Service Charges', 'Monthly or as incurred', 'USD 10–USD 500 per month', true, '8580'],
        ['Credit card processing fees', 'Comisiones por procesamiento de tarjetas', 'operating_expense', 'Financial and Banking Expenses', 'Merchant Fees', 'Per transaction / monthly', 'Approximately 2%–4% of card sales', false, '8030'],
        ['Bad debt', 'Cuentas incobrables', 'operating_expense', 'Financial and Banking Expenses', 'Bad Debt Expense', 'Monthly or annual adjustment', 'Approximately 0.5%–3% of credit sales', false, '8580'],
        ['Taxes and government fees', 'Impuestos y tasas gubernamentales', 'operating_expense', 'General Expenses', 'Taxes and Fees Expense', 'Monthly, quarterly or annual', 'USD 100–USD 20,000+ per year, excluding income/payroll taxes', true, '8550'],
        ['Miscellaneous expenses', 'Gastos varios', 'operating_expense', 'General Expenses', 'Miscellaneous Expense', 'As incurred', 'USD 50–USD 2,000 per month', false, '8580'],
        ['Interest paid', 'Intereses pagados', 'other_expense', 'Other Expenses', 'Interest Expense', 'Monthly', 'USD 100–USD 20,000+ per month', true, '8580'],
        ['Foreign exchange losses', 'Pérdidas cambiarias', 'other_expense', 'Other Expenses', 'Foreign Exchange Loss', 'As incurred / monthly', 'Variable; commonly 0.5%–3% of foreign-currency transactions', false, '8580'],
        ['Computer equipment', 'Equipo de computación', 'asset', 'Fixed Assets', 'Computer Equipment — Fixed Asset', 'One-time / as purchased', 'USD 800–USD 3,000 per employee', true, '1110'],
        ['Vehicles', 'Vehículos', 'asset', 'Fixed Assets', 'Vehicles — Fixed Asset', 'One-time / as purchased', 'USD 25,000–USD 80,000 per vehicle', false, '1140'],
        ['Machinery', 'Maquinaria', 'asset', 'Fixed Assets', 'Machinery and Equipment — Fixed Asset', 'One-time / as purchased', 'USD 5,000–USD 250,000+ per machine', false, '1130'],
        ['Leasehold improvements', 'Mejoras a locales arrendados', 'asset', 'Fixed Assets', 'Leasehold Improvements — Fixed Asset', 'One-time / as incurred', 'USD 10,000–USD 250,000+ per project', false, '1130'],
    ];

    /** Account type of a code (by its first digit). */
    public static function typeOf(string $code): string
    {
        return match ($code[0] ?? '') {
            '1' => 'asset',
            '2' => 'liability',
            '3' => 'equity',
            '4', '5' => 'income',
            default => 'expense',
        };
    }

    public static function isGroup(string $code): bool
    {
        return str_contains($code, '-');
    }

    /** The group header a code belongs to ("1110" -> "1100-1999"), or null for a header. */
    public static function groupOf(string $code): ?string
    {
        if (self::isGroup($code)) {
            return null;
        }
        $n = (int) $code;
        $best = null;
        foreach (self::ACCOUNTS as [$c]) {
            if (! self::isGroup($c)) {
                continue;
            }
            [$from, $to] = array_map('intval', explode('-', $c));
            // The tightest range that contains the code (1110 -> 1100-1999, not 1000-1099).
            if ($n >= $from && $n <= $to && (! $best || ($to - $from) < ($best[1] - $best[0]))) {
                $best = [$from, $to, $c];
            }
        }

        return $best[2] ?? null;
    }
}
