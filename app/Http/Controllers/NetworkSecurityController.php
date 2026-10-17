<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\NetworkAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings -> Network security (2026-10-16), permission network_security, for
 * the acting company (a SuperAdmin's selected company, else the user's own):
 * the company switch, its networks, the default for users, each staff user's
 * own choice, and the computers waiting for approval / approved.
 * See App\Support\NetworkAccess for how it is enforced.
 */
class NetworkSecurityController extends Controller
{
    use ScopesToCompany;

    private function guard(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'network_security'), 403, 'Your role can\'t manage network security.');

        return $this->resolveActingCompanyId($request);
    }

    public function show(Request $request)
    {
        $companyId = $this->guard($request);
        NetworkAccess::forget($companyId);
        $c = NetworkAccess::company($companyId);

        $users = DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotIn('type', ['SuperAdmin', 'customer', 'supplier'])
            ->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'email', 'type', 'statut', 'network_access']);

        $devices = DB::table('trusted_devices as d')->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('users as a', 'a.id', '=', 'd.approved_by')
            ->where('d.company_id', $companyId)->where('d.status', '!=', 'revoked')
            ->orderByRaw("d.status = 'pending' DESC")->orderByDesc('d.updated_at')
            ->get(['d.id', 'd.user_id', 'd.name', 'd.status', 'd.requested_ip', 'd.last_ip', 'd.last_used_at', 'd.approved_at', 'd.created_at',
                DB::raw("TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.lastname,''))) as user_name"),
                DB::raw("TRIM(CONCAT(COALESCE(a.firstname,''),' ',COALESCE(a.lastname,''))) as approved_by_name")]);

        return response()->json([
            'enabled' => $c['enabled'],
            'allowed' => $c['allowed'],
            'default_restricted' => $c['default_restricted'],
            'current_ip' => $request->ip(),
            'users' => $users->map(fn ($u) => [
                'id' => $u->id, 'name' => trim($u->firstname.' '.$u->lastname), 'email' => $u->email, 'type' => $u->type,
                'active' => (bool) $u->statut, 'network_access' => $u->network_access,
            ]),
            'devices' => $devices,
        ]);
    }

    public function update(Request $request)
    {
        $companyId = $this->guard($request);
        $request->validate(['enabled' => 'required|boolean', 'default_restricted' => 'required|boolean', 'allowed' => 'array']);
        $allowed = NetworkAccess::cleanList((array) $request->input('allowed', []));
        $bad = array_filter(array_map(fn ($r) => trim((string) ($r['ip'] ?? '')), (array) $request->input('allowed', [])), fn ($ip) => $ip !== '' && ! NetworkAccess::validIpOrRange($ip));
        if ($bad) {
            return response()->json(['message' => 'Not a valid IP address or range: '.implode(', ', $bad)], 422);
        }

        // Lockout guard: saving a setup that would block the person saving it
        // needs an explicit confirmation (the SuperAdmin is never blocked).
        $me = $request->user('api');
        if ($me->type !== 'SuperAdmin' && (int) $me->company_id === $companyId && $request->boolean('enabled') && ! $request->boolean('confirm_lockout')) {
            $restricted = $me->network_access === 'restricted' || ($me->network_access !== 'anywhere' && $request->boolean('default_restricted'));
            $onList = $allowed && \Symfony\Component\HttpFoundation\IpUtils::checkIp((string) $request->ip(), array_column($allowed, 'ip'));
            if ($restricted && ! $onList && ! NetworkAccess::approvedDevice($me, $request)) {
                return response()->json([
                    'code' => 'self_lockout',
                    'message' => 'This would block you: your current IP ('.$request->ip().') is not in the list and this computer is not approved.',
                ], 409);
            }
        }

        DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->update([
            'network_security_enabled' => $request->boolean('enabled'),
            'network_default_restricted' => $request->boolean('default_restricted'),
            'network_allowed' => json_encode(array_values($allowed)),
            'updated_at' => now(),
        ]);
        NetworkAccess::forget($companyId);

        return $this->show($request);
    }

    /** One staff user's choice: null (company default) | restricted | anywhere. */
    public function userAccess(Request $request, $id)
    {
        $companyId = $this->guard($request);
        $request->validate(['network_access' => 'nullable|in:restricted,anywhere']);
        $user = DB::table('users')->where('id', $id)->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotIn('type', ['SuperAdmin', 'customer', 'supplier'])->first(['id']);
        abort_unless($user, 404);
        DB::table('users')->where('id', $id)->update(['network_access' => $request->input('network_access') ?: null]);

        return response()->json(['success' => true]);
    }

    public function approve(Request $request, $id)
    {
        $companyId = $this->guard($request);
        $n = DB::table('trusted_devices')->where('id', $id)->where('company_id', $companyId)
            ->update(['status' => 'approved', 'approved_by' => $request->user('api')->id, 'approved_at' => now(), 'updated_at' => now()]);
        abort_unless($n, 404);

        return response()->json(['success' => true]);
    }

    /** Remove an approved computer, or refuse a request (it can ask again). */
    public function remove(Request $request, $id)
    {
        $companyId = $this->guard($request);
        $n = DB::table('trusted_devices')->where('id', $id)->where('company_id', $companyId)
            ->update(['status' => 'revoked', 'updated_at' => now()]);
        abort_unless($n, 404);

        return response()->json(['success' => true]);
    }
}
