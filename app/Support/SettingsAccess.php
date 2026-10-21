<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Settings a company's roles may NOT have by default (user's rule, 2026-10-20):
 * only the SuperAdmin manages them. A company keeps: System Settings (company
 * info, logo, defaults), Appearance, Customize columns, Email templates,
 * Locations / warehouses, Network security and Login devices (2026-10-20: Mail
 * settings, Payment methods and SMS templates moved to SuperAdmin-only -- every
 * company uses AdminSystem's mail server). Everything else in Settings is SuperAdmin-only --
 * except a module's own settings when that module is ON for the company
 * (PayPal for KcolTech, POS settings with POS...).
 *
 * Applied: to every company's roles once (migration), to a new company's roles
 * on create (CompanyController), and on every role save by a company Admin
 * (PermissionsController) -- an Admin can't grant them back. The SuperAdmin's
 * own roles (AdminSystem, 999) are never touched; a SuperAdmin editing a
 * company's role may grant them on purpose.
 */
class SettingsAccess
{
    /** permission => the module that allows it (null = SuperAdmin only, always). */
    public const RESTRICTED = [
        'paypal_manage' => 'paypal',
        'zatca_settings' => 'zatca',
        'pos_settings' => 'pos',
        'woocommerce_settings' => 'woocommerce_settings',
        'quickbooks_settings' => 'quickbooks',
        'slack_settings' => 'slack',
        'telegram_settings' => 'telegram',
        'salla_settings' => 'salla',
        'xero_settings' => 'xero',
        'prestashop_settings' => 'prestashop',
        'google_sheets_settings' => 'google_sheets',
        'mailchimp_settings' => 'mailchimp',
        'jumia_settings' => 'jumia',
        'webhooks_view' => 'integrations',
        'webhooks_add' => 'integrations',
        'webhooks_edit' => 'integrations',
        'webhooks_delete' => 'integrations',
        'currency' => null,
        'multi_currency' => null,
        'business_modules' => null,
        'update_settings' => null,
        'backup' => null,
        'system_health_view' => null,
        'translations_settings' => null,
        'payment_gateway' => null,
        'sms_settings' => null,
        'loyalty_rewards' => null,
        'mail_settings' => null,
        'payment_methods' => null,
        'sms_templates' => null,
    ];

    public static function allowed(int $companyId, string $permission): bool
    {
        if (! array_key_exists($permission, self::RESTRICTED)) {
            return true;
        }
        $module = self::RESTRICTED[$permission];

        return $module !== null && ModuleFlags::enabled($companyId, $module);
    }

    /** The permission names a company's role may hold, of these. */
    public static function filter(int $companyId, array $permissions): array
    {
        return array_values(array_filter($permissions, fn ($p) => self::allowed($companyId, (string) $p)));
    }

    /** Remove the restricted permissions from every role of the company. Returns rows removed. */
    public static function trimRoles(int $companyId): int
    {
        if ($companyId === \App\Models\Setting::TEMPLATE_COMPANY_ID) {
            return 0;
        }
        $deny = array_keys(array_filter(self::RESTRICTED, fn ($module, $p) => ! self::allowed($companyId, $p), ARRAY_FILTER_USE_BOTH));
        $permIds = DB::table('permissions')->whereIn('name', $deny)->pluck('id');
        $roleIds = DB::table('roles')->where('company_id', $companyId)->pluck('id');
        if ($permIds->isEmpty() || $roleIds->isEmpty()) {
            return 0;
        }

        return DB::table('permission_role')->whereIn('role_id', $roleIds)->whereIn('permission_id', $permIds)->delete();
    }
}
