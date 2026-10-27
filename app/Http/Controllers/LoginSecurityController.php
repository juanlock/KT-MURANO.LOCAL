<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Setting;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\LoginSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Login security (2026-10-27), App\Support\LoginSecurity:
 *  - my_2fa*: each person's own two-step verification (Profile) -- email codes on /
 *    off, the authenticator app (QR, confirm, recovery codes, off), forget the
 *    computers remembered for 30 days.
 *  - login_security*: Settings -> Login security for the acting company (permission
 *    login_security): which user types need the two-step code, sign-in alerts,
 *    locked logins (unlock), logins with an authenticator (reset), recent activity.
 */
class LoginSecurityController extends Controller
{
    use ScopesToCompany;

    // ------------------------------------------------------------ my own

    public function my(Request $request)
    {
        // fresh(): the actions below change the row after the request loaded the user.
        $u = $request->user('api')->fresh();

        return response()->json([
            'required' => $this->requiredByCompany($u),
            'email_on' => (bool) $u->two_factor_email,
            'app_on' => (bool) $u->two_factor_confirmed_at,
            'active' => LoginSecurity::twoFactorRequired($u),
            'demo' => LoginSecurity::isDemo($u),
            'recovery_left' => $u->two_factor_recovery ? count(json_decode(Crypt::decryptString($u->two_factor_recovery), true) ?: []) : 0,
            'remembered' => DB::table('login_devices')->where('user_id', $u->id)->where('remember_until', '>', now())->count(),
            'password_changed_at' => $u->password_changed_at,
        ]);
    }

    /** {on: bool} -- email codes by the user's own choice (the company may require them anyway). */
    public function myEmail(Request $request)
    {
        $u = $request->user('api');
        $on = $request->boolean('on');
        DB::table('users')->where('id', $u->id)->update(['two_factor_email' => $on]);
        LoginSecurity::event($u, $u->email, 'web', $on ? '2fa_on' : '2fa_off', $request, 'email');

        return $this->my($request);
    }

    /** A new authenticator secret (not active until confirmed with a code). */
    public function myAppStart(Request $request)
    {
        $u = $request->user('api');
        abort_if($u->two_factor_confirmed_at, 422, LoginSecurity::es() ? 'La app ya está activa.' : 'The app is already on.');
        $secret = LoginSecurity::newSecret();
        DB::table('users')->where('id', $u->id)->update(['two_factor_secret' => Crypt::encryptString($secret), 'two_factor_confirmed_at' => null]);

        return response()->json(['secret' => $secret, 'uri' => LoginSecurity::otpauthUri($u, $secret)]);
    }

    /** {code} -- the app's first code turns it on; returns the recovery codes (shown once). */
    public function myAppConfirm(Request $request)
    {
        $u = $request->user('api')->fresh();
        $code = preg_replace('/\s+/', '', (string) $request->input('code'));
        abort_unless($u->two_factor_secret && preg_match('/^\d{6}$/', $code), 422, LoginSecurity::es() ? 'Código incorrecto.' : 'Wrong code.');
        $secret = Crypt::decryptString($u->two_factor_secret);
        $ok = false;
        foreach ([-1, 0, 1] as $w) {
            $ok = $ok || hash_equals(LoginSecurity::totp($secret, intdiv(time(), 30) + $w), $code);
        }
        abort_unless($ok, 422, LoginSecurity::es() ? 'Código incorrecto: revise la hora del teléfono y vuelva a intentar.' : 'Wrong code: check the phone\'s clock and try again.');
        DB::table('users')->where('id', $u->id)->update(['two_factor_confirmed_at' => now()]);
        LoginSecurity::event($u, $u->email, 'web', '2fa_on', $request, 'app');

        return response()->json(['recovery_codes' => LoginSecurity::newRecoveryCodes((int) $u->id)] + $this->my($request)->getData(true));
    }

    /** {password} -- turn the app off (back to email codes when the company requires them). */
    public function myAppDisable(Request $request)
    {
        $u = $request->user('api');
        $this->checkPassword($u, $request);
        DB::table('users')->where('id', $u->id)->update(['two_factor_secret' => null, 'two_factor_recovery' => null, 'two_factor_confirmed_at' => null]);
        LoginSecurity::event($u, $u->email, 'web', '2fa_off', $request, 'app');

        return $this->my($request);
    }

    /** {password} -- new recovery codes (the old ones stop working). */
    public function myRecovery(Request $request)
    {
        $u = $request->user('api');
        $this->checkPassword($u, $request);
        abort_unless($u->two_factor_confirmed_at, 422);

        return response()->json(['recovery_codes' => LoginSecurity::newRecoveryCodes((int) $u->id)]);
    }

    /** Computers remembered for the two-step code ask for it again. */
    public function myForgetDevices(Request $request)
    {
        $u = $request->user('api');
        DB::table('login_devices')->where('user_id', $u->id)->update(['remember_until' => null, 'updated_at' => now()]);

        return $this->my($request);
    }

    private function checkPassword(object $u, Request $request): void
    {
        abort_unless(Hash::check((string) $request->input('password'), (string) DB::table('users')->where('id', $u->id)->value('password')), 422,
            LoginSecurity::es() ? 'La contraseña no es correcta.' : 'The password is not right.');
    }

    private function requiredByCompany(object $u): bool
    {
        $type = $u->type === 'SuperAdmin' ? 'SuperAdmin' : strtolower((string) $u->type);

        return in_array($type, LoginSecurity::settings(LoginSecurity::companyOf($u))['types'], true);
    }

    // ------------------------------------------------- company settings

    private function guard(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'login_security'), 403, 'Your role can\'t manage login security.');

        return $this->resolveActingCompanyId($request);
    }

    public function show(Request $request)
    {
        $cid = $this->guard($request);
        $isSystem = $cid === Setting::TEMPLATE_COMPANY_ID;
        $s = LoginSecurity::settings($cid);
        // AdminSystem's settings are the SuperAdmin's; its users are every SuperAdmin.
        $users = fn () => DB::table('users')->whereNull('deleted_at')
            ->when($isSystem, fn ($q) => $q->where('type', 'SuperAdmin'), fn ($q) => $q->where('company_id', $cid)->where('type', '!=', 'SuperAdmin'));
        $person = fn ($u) => ['id' => $u->id, 'name' => trim($u->firstname.' '.$u->lastname) ?: $u->username, 'email' => $u->email, 'type' => $u->type];

        return response()->json([
            'types' => $s['types'],
            'alerts' => $s['alerts'],
            'type_options' => $isSystem ? ['SuperAdmin'] : LoginSecurity::TYPES,
            'type_counts' => $users()->selectRaw('type, count(*) c')->groupBy('type')->pluck('c', 'type'),
            'locked' => $users()->where('locked_until', '>', now())->get(['id', 'firstname', 'lastname', 'username', 'email', 'type', 'locked_until'])
                ->map(fn ($u) => $person($u) + ['locked_until' => $u->locked_until]),
            'with_app' => $users()->whereNotNull('two_factor_confirmed_at')->get(['id', 'firstname', 'lastname', 'username', 'email', 'type', 'two_factor_confirmed_at'])
                ->map(fn ($u) => $person($u) + ['since' => $u->two_factor_confirmed_at]),
            'events' => DB::table('login_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->when($isSystem, fn ($q) => $q->where('u.type', 'SuperAdmin'), fn ($q) => $q->where('e.company_id', $cid)->where(fn ($w) => $w->whereNull('u.type')->orWhere('u.type', '!=', 'SuperAdmin')))
                ->orderByDesc('e.id')->limit(100)
                ->get(['e.id', 'e.created_at', 'e.email', 'e.channel', 'e.event', 'e.ip', 'e.device', 'e.note',
                    DB::raw("TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.lastname,''))) as user_name")]),
        ]);
    }

    public function update(Request $request)
    {
        $cid = $this->guard($request);
        $allowed = $cid === Setting::TEMPLATE_COMPANY_ID ? ['SuperAdmin'] : LoginSecurity::TYPES;
        $data = $request->validate(['types' => 'array', 'types.*' => 'in:'.implode(',', $allowed), 'alerts' => 'required|boolean']);
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update([
            'security_2fa_types' => json_encode(array_values(array_unique($data['types'] ?? []))),
            'security_login_alerts' => $request->boolean('alerts'),
            'updated_at' => now(),
        ]);

        return $this->show($request);
    }

    public function unlock(Request $request, $id)
    {
        $cid = $this->guard($request);
        // superadmin/AdminSystem unlocks any login (Users lists every company there).
        $me = $request->user('api');
        if (! ($me->type === 'SuperAdmin' && $cid === Setting::TEMPLATE_COMPANY_ID)) {
            $this->ownUser($cid, (int) $id);
        }
        LoginSecurity::unlock((int) $id, $request->user('api'), $request);

        return $this->show($request);
    }

    /** The person lost the phone: the authenticator is removed (email codes again when required). */
    public function resetApp(Request $request, $id)
    {
        $cid = $this->guard($request);
        $u = $this->ownUser($cid, (int) $id);
        DB::table('users')->where('id', $u->id)->update(['two_factor_secret' => null, 'two_factor_recovery' => null, 'two_factor_confirmed_at' => null]);
        LoginSecurity::event($u, $u->email, 'web', '2fa_off', $request, 'reset by '.$request->user('api')->email);

        return $this->show($request);
    }

    private function ownUser(int $cid, int $id): object
    {
        $u = DB::table('users')->where('id', $id)->whereNull('deleted_at')->first();
        $mine = $u && ($cid === Setting::TEMPLATE_COMPANY_ID ? $u->type === 'SuperAdmin' : ((int) $u->company_id === $cid && $u->type !== 'SuperAdmin'));
        abort_unless($mine, 404);

        return $u;
    }
}
