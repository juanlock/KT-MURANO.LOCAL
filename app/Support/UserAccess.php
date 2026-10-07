<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Turning a login off for real (2026-10-07). statut = 0 alone left the user's
 * open sessions working: the API token / SPA cookie stayed valid until it
 * expired. disable() also revokes every token and login session, so the next
 * request of any device is refused (Is_Active, EnforceApiTokenTimeout,
 * LogoutRevokedWebSession). Used by: removing an employee (EmployeeRemoval),
 * Users -> Disabled, Users -> Delete. Call it inside the caller's transaction.
 */
class UserAccess
{
    /** Disable the login and sign it out everywhere. Returns how many sessions / tokens were cut. */
    public static function disable(int $userId): int
    {
        DB::table('users')->where('id', $userId)->update(['statut' => 0, 'remember_token' => null, 'updated_at' => now()]);

        return self::signOutEverywhere($userId);
    }

    /** Revoke every Passport token (+ refresh tokens) and login session of the user. */
    public static function signOutEverywhere(int $userId): int
    {
        $tokenIds = DB::table('oauth_access_tokens')->where('user_id', $userId)->where('revoked', 0)->pluck('id');
        if ($tokenIds->isNotEmpty()) {
            DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => 1, 'updated_at' => now()]);
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => 1]);
        }
        $sessions = DB::table('user_login_sessions')->where('user_id', $userId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return $tokenIds->count() + $sessions;
    }
}
