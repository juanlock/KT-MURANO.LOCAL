<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Server side of System Settings -> Business Modules: settings.module_flags
 * ({key: bool}, per company). A null map or a missing key = ENABLED, same
 * rule as resources/src/config/modules.js (isModuleEnabled).
 */
class ModuleFlags
{
    /** OFF until turned on for a company (Saudi e-invoicing; PayPal invoice payments). Same list as config/modules.js defaultOff. */
    public const DEFAULT_OFF = ['zatca', 'paypal',
        // Integrations hub / webhooks and every integration (2026-10-20): their
        // settings are install-wide, so they're off unless a SuperAdmin turns one on.
        'integrations', 'woocommerce_settings', 'shopify', 'salla', 'jumia', 'prestashop',
        'quickbooks', 'xero', 'google_sheets', 'mailchimp', 'slack', 'telegram'];

    private static array $memo = [];

    public static function enabled(?int $companyId, string $key): bool
    {
        if (! $companyId) {
            return ! in_array($key, self::DEFAULT_OFF, true);
        }
        if (! array_key_exists($companyId, self::$memo)) {
            $raw = Setting::where('company_id', $companyId)->whereNull('deleted_at')->value('module_flags');
            $flags = is_string($raw) ? json_decode($raw, true) : $raw;
            self::$memo[$companyId] = is_array($flags) ? $flags : [];
        }

        if (in_array($key, self::DEFAULT_OFF, true)) {
            return (self::$memo[$companyId][$key] ?? false) === true;
        }

        return (self::$memo[$companyId][$key] ?? true) !== false;
    }

    /**
     * 403 (staff API) when the module is switched off for the company.
     * Never for the SuperAdmin (user's rule, 2026-10-19): the system's owner
     * sees and opens every module of every company. (Business logic that
     * follows a switch -- e.g. ZATCA reporting a sale -- uses enabled().)
     */
    public static function abortUnlessEnabled(?int $companyId, string $key, int $status = 403): void
    {
        if (optional(auth('api')->user() ?? auth()->user())->type === 'SuperAdmin') {
            return;
        }
        abort_unless(self::enabled($companyId, $key), $status, "The {$key} module is turned off for this company.");
    }
}
