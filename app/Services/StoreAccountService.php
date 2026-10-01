<?php

namespace App\Services;

use App\Models\EcommerceClient;
use App\Models\StoreSetting;

/**
 * Web store accounts (ecommerce_clients) belong to the store's company, and
 * their linked users row (type Online_store, the company's "Online Store"
 * role) only exists once the account is CONFIRMED, as that company's Store
 * Settings require:
 *  - require_email_verification -> the customer clicked the emailed link;
 *  - require_admin_approval     -> an admin approved it (Store -> Pending
 *                                  Customers);
 *  - both on -> both must have happened; both off -> right at sign-up.
 * Called from StoreAuthController (register / verifyEmail) and
 * PendingCustomersController (approve / approveAll).
 */
class StoreAccountService
{
    /** That company's store settings (falls back to the first row, as before). */
    public static function settingsFor(?int $companyId): ?StoreSetting
    {
        return ($companyId ? StoreSetting::where('company_id', $companyId)->first() : null)
            ?? StoreSetting::query()->orderBy('id')->first();
    }

    /** Store settings of the company an account belongs to. */
    public static function settingsForAccount(EcommerceClient $account): ?StoreSetting
    {
        return self::settingsFor(optional($account->client)->company_id);
    }

    public static function isConfirmed(EcommerceClient $account, ?StoreSetting $s = null): bool
    {
        $s = $s ?? self::settingsForAccount($account);
        $emailOk = ! ($s->require_email_verification ?? true) || $account->email_verified_at;

        return $emailOk && (int) $account->status === 1;
    }

    /**
     * Creates the linked users row once the account is confirmed; no-op
     * otherwise, or when the customer already has one. Returns the new user
     * id or null.
     */
    public static function activateIfConfirmed(EcommerceClient $account): ?int
    {
        $client = $account->client;
        if (! $client || ! self::isConfirmed($account)) {
            return null;
        }

        return LinkedUserService::ensureForClient($client, 'Online_store', 'Online Store');
    }
}
