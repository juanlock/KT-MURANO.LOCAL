<?php

use App\Support\SchoolRoles;
use App\Support\SettingsAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * User's rules, 2026-10-20 (safe to run twice; by type / name, not id):
 *  1. Integrations OFF for every company (their settings are install-wide):
 *     the Integrations hub & Webhooks and every integration switch set to off
 *     in each company's module_flags; their settings permissions then leave
 *     the company's roles (SettingsAccess). AdminSystem (999) not touched --
 *     the SuperAdmin sees everything anyway.
 *  2. School companies get the roles Rector, Secretaria, Profesor, Padre de
 *     familia and Alumno (SchoolRoles; created when missing).
 */
return new class extends Migration
{
    private const OFF = ['integrations', 'woocommerce_settings', 'shopify', 'salla', 'jumia', 'prestashop',
        'quickbooks', 'xero', 'google_sheets', 'mailchimp', 'slack', 'telegram'];

    public function up(): void
    {
        $template = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        foreach (DB::table('settings')->whereNull('deleted_at')->where('company_id', '!=', $template)->get(['id', 'company_id', 'module_flags']) as $row) {
            $flags = json_decode((string) $row->module_flags, true) ?: [];
            foreach (self::OFF as $k) {
                $flags[$k] = false;
            }
            DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
        }
        if (Schema::hasTable('permission_role')) {
            foreach (DB::table('companies')->whereNull('deleted_at')->pluck('id') as $cid) {
                SettingsAccess::trimRoles((int) $cid);
            }
        }

        if (Schema::hasColumn('companies', 'business_type')) {
            foreach (DB::table('companies')->where('business_type', 'school')->whereNull('deleted_at')->pluck('id') as $cid) {
                DB::transaction(fn () => SchoolRoles::ensure((int) $cid));
            }
        }
    }

    public function down(): void
    {
        // Switches can be turned back on in Business Modules; the school roles stay.
    }
};
