<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * HORDEX EIRL's modules (user, 2026-11-20: "solo deberían estar prendidos sus módulos"). The company was created by a
 * script that saved only its business type's modules, and a module missing from settings.module_flags counts as ON, so
 * Hospital, School... showed for its IT Admin. Now the whole map: ON only what its earlier system had (B2B, sales,
 * purchases, suppliers, products, customers); everything else OFF. By name; safe to run twice.
 */
return new class extends Migration
{
    private const ON = ['b2b', 'sales', 'purchases', 'suppliers', 'products', 'customers'];

    private const ALL = ['accounting', 'assets', 'b2b', 'bookings', 'callcenter', 'commissions', 'customers', 'documents', 'email_office',
        'fleet', 'forms', 'gallery', 'google_sheets', 'helpdesk', 'hospital', 'hrm', 'integrations', 'intranet', 'jumia', 'kitchen',
        'mailchimp', 'manufacturing', 'marketing', 'meeting', 'paypal', 'portfolio', 'pos', 'prestashop', 'products', 'projects',
        'promotions', 'purchases', 'quickbooks', 'realestate', 'recruit', 'sales', 'salla', 'school', 'service', 'shopify', 'slack',
        'subscription_product', 'suppliers', 'telegram', 'transport', 'website', 'woocommerce_settings', 'xero', 'zatca'];

    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'HORDEX EIRL')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $flags = [];
        foreach (self::ALL as $k) {
            $flags[$k] = in_array($k, self::ON, true);
        }
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
    }

    public function down(): void {}
};
