<?php

namespace App\Http\Controllers;

use App\Support\LoginSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Install the app on this computer" (2026-11-20, user: once installed, that PC must not ask the password again): the
 * signed-in login's device row (cookie LoginSecurity::DEVICE_COOKIE -> login_devices) is trusted for 5 years -- no two-step
 * code there, whatever the IP (users.two_factor_ip included); the session itself already lasts a year on that browser.
 * The device shows in Login devices and can be removed there.
 *
 *  POST device/trust {name?}
 */
class DeviceTrustController extends Controller
{
    public function trust(Request $request)
    {
        $user = $request->user('api');
        $key = (string) $request->cookie(LoginSecurity::DEVICE_COOKIE, '');
        abort_if(strlen($key) < 40, 422, __('This browser has no device key yet: sign out and in again.'));
        $hash = hash('sha256', $key);
        $values = ['remember_until' => now()->addYears(5), 'last_ip' => $request->ip(), 'last_seen_at' => now(), 'updated_at' => now()];
        if ($request->filled('name')) {
            $values['name'] = mb_substr((string) $request->name, 0, 120);
        }
        if (DB::table('login_devices')->where('user_id', $user->id)->where('token_hash', $hash)->exists()) {
            DB::table('login_devices')->where('user_id', $user->id)->where('token_hash', $hash)->update($values);
        } else {
            DB::table('login_devices')->insert($values + ['user_id' => $user->id, 'token_hash' => $hash,
                'name' => $values['name'] ?? \App\Support\NetworkAccess::deviceName((string) $request->userAgent()), 'created_at' => now()]);
        }
        LoginSecurity::event($user, $user->email, 'web', 'device_trusted', $request);

        return response()->json(['ok' => true]);
    }
}
