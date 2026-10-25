<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Support\CompanyLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * GET company-logins (user's rule, 2026-10-25): "Company logins" in the profile
 * menu, for the company's admin only (an admin login whose role has
 * company_logins_view; not the SuperAdmin) -- every login of the
 * acting company with its role, the role's accesses and its password when it
 * is still the standard one of its type (CompanyLogins). Passwords are stored
 * hashed: a changed one can't be read back and shows as "custom".
 */
class CompanyLoginsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless($this->allowed($user), 403, 'Your role cannot see the company logins.');
        $cid = \App\Support\CompanyFiles::actingCompanyId($request);

        $users = DB::table('users')->where('company_id', $cid)->whereNull('deleted_at')
            ->orderByRaw("FIELD(type, 'admin', 'employee', 'sales_agent', 'supplier', 'customer', 'Online_store')")->orderBy('firstname')
            ->get(['id', 'firstname', 'lastname', 'username', 'email', 'type', 'statut', 'password', 'role_id', 'portal_enabled']);
        $roleIds = DB::table('role_user')->whereIn('user_id', $users->pluck('id'))->pluck('role_id', 'user_id');
        $roles = DB::table('roles')->whereIn('id', $roleIds->values()->merge($users->pluck('role_id'))->filter()->unique())
            ->get(['id', 'name', 'label', 'description'])->keyBy('id');
        $perms = DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->whereIn('pr.role_id', $roles->keys())->where('p.name', 'not like', 'menu_hide:%')
            ->get(['pr.role_id', 'p.name'])->groupBy('role_id')->map(fn ($g) => $g->pluck('name')->values());

        $assigned = $this->assignedPatients($cid, $users->pluck('id')->all());
        $types = ['admin' => null, 'employee' => 'employee', 'customer' => 'customer', 'supplier' => 'supplier', 'sales_agent' => 'sales_agent', 'Online_store' => 'online_store'];

        return response()->json([
            'logins' => $users->map(function ($u) use ($roleIds, $roles, $types, $assigned) {
                $role = $roles[$roleIds[$u->id] ?? $u->role_id] ?? null;
                // Its type's standard password, else the access one (a helpdesk agent is an employee).
                $candidates = array_filter([$u->type === 'admin' ? null : (CompanyLogins::PASSWORDS[$types[$u->type] ?? ''] ?? null), CompanyLogins::ACCESS_PASSWORD]);
                $standard = collect($candidates)->first(fn ($pw) => $this->matches($u->password, $pw));

                return [
                    'id' => $u->id, 'name' => trim($u->firstname.' '.$u->lastname), 'username' => $u->username, 'email' => $u->email,
                    'type' => $u->type, 'active' => (int) $u->statut === 1,
                    'portal' => $u->type === 'customer' ? (int) $u->portal_enabled === 1 : null,
                    // Hospital staff: the patients they have today (null = not hospital staff).
                    'patients' => $assigned[$u->id] ?? null,
                    'role_id' => $role->id ?? null, 'role' => $role->name ?? null, 'role_description' => $role->description ?? null,
                    'password' => $standard,
                ];
            })->values(),
            'role_permissions' => $perms,
        ]);
    }

    /**
     * POST /login/as/{id} (web): "Enter" -- this browser works as that login
     * (user's rules, 2026-10-25: switch + back, same tab, any login of the
     * admin's own company whatever its password; logged). A customer opens
     * the customer portal, which has its own session, so the admin's stays.
     */
    public function open(Request $request, $id)
    {
        $admin = \Illuminate\Support\Facades\Auth::guard('web')->user();
        abort_unless($this->allowed($admin), 403, 'Only the company admin can open other logins.');
        $target = \App\Models\User::whereKey((int) $id)->where('company_id', $admin->company_id)->whereNull('deleted_at')->first();
        abort_unless($target, 404);
        abort_if((int) $target->id === (int) $admin->id, 422, 'That is your own login.');
        abort_unless((int) $target->statut === 1, 422, 'That login is disabled.');
        // Any login of the admin's own company (user's rule, 2026-10-25), whatever its password -- never a SuperAdmin.
        abort_if($target->type === 'SuperAdmin', 403);

        \Illuminate\Support\Facades\Log::info('Company admin opened a login', ['admin_id' => $admin->id, 'target_id' => $target->id, 'company_id' => $admin->company_id, 'ip' => $request->ip()]);

        if ($target->type === 'customer') {
            $portal = \App\Models\PortalClient::find($target->id);
            abort_unless($portal, 404);
            if ((int) $portal->status !== 1) {
                // The admin is asked first; on yes the portal is turned on (as People -> Customers does).
                if (! $request->boolean('enable_portal')) {
                    return response()->json(['needs_portal' => true, 'message' => 'This customer has no portal access yet.'], 409);
                }
                DB::table('users')->where('id', $portal->id)->update(['portal_enabled' => 1, 'invitation_token' => null, 'updated_at' => now()]);
                $portal = \App\Models\PortalClient::find($target->id);
            }
            \Illuminate\Support\Facades\Auth::guard('portal')->login($portal);

            return response()->json(['redirect' => url('/portal/dashboard')]);
        }
        abort_unless(in_array($target->type, ['admin', 'employee', 'sales_agent', 'supplier'], true), 422, 'This kind of login cannot be opened here.');

        \Illuminate\Support\Facades\Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');
        \App\Support\AdminSwitch::start($admin, $target);

        return response()->json(['redirect' => url('/')]);
    }

    /** POST /login/as-back (web): back to the admin who opened this login. */
    public function back(Request $request)
    {
        $current = \Illuminate\Support\Facades\Auth::guard('web')->user();
        $admin = \App\Support\AdminSwitch::adminFor($current, $request);
        abort_unless($admin, 403, 'Not working as another login.');
        \Illuminate\Support\Facades\Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        \App\Support\AdminSwitch::stop();

        return response()->json(['redirect' => url('/')]);
    }

    /** GET session-user (api): who this browser is now (another tab may have switched it). */
    public function whoami(Request $request)
    {
        return response()->json(['id' => optional($request->user('api'))->id]);
    }

    /**
     * Hospital (2026-10-25): for each nurse / doctor login, the patients they
     * have today -- the ones on their roster rows of today, plus (doctors) the
     * admitted patients they attend. user id => [names]. Empty when the company
     * has no hospital staff.
     */
    private function assignedPatients(int $cid, array $userIds): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hms_staff_shifts')) {
            return [];
        }
        $employees = DB::table('employees')->where('company_id', $cid)->whereIn('user_id', $userIds ?: [0])->whereNull('deleted_at')->pluck('user_id', 'id');
        $doctors = DB::table('doctors')->where('company_id', $cid)->whereIn('employee_id', $employees->keys())->whereNull('deleted_at')->pluck('employee_id', 'id');
        if ($employees->isEmpty()) {
            return [];
        }
        $userOfDoctor = fn ($docId) => $employees[$doctors[$docId] ?? 0] ?? null;
        $out = [];
        $rows = DB::table('hms_staff_shifts as s')->join('hms_shift_patients as sp', 'sp.staff_shift_id', '=', 's.id')
            ->where('s.company_id', $cid)->whereNull('s.deleted_at')->whereDate('s.shift_date', now()->toDateString())
            ->get(['s.employee_id', 's.doctor_id', 'sp.patient_id']);
        foreach ($rows as $r) {
            $uid = $r->doctor_id ? $userOfDoctor($r->doctor_id) : ($employees[$r->employee_id] ?? null);
            if ($uid) {
                $out[$uid][$r->patient_id] = true;
            }
        }
        foreach (DB::table('admissions')->where('company_id', $cid)->where('status', 'admitted')->whereNull('deleted_at')
            ->whereIn('doctor_id', $doctors->keys())->get(['doctor_id', 'patient_id']) as $a) {
            if ($uid = $userOfDoctor($a->doctor_id)) {
                $out[$uid][$a->patient_id] = true;
            }
        }
        // Every hospital staff login shows a count, 0 included.
        $staff = $employees->values()->all();
        $names = DB::table('patients')->whereIn('id', collect($out)->flatMap(fn ($p) => array_keys($p))->unique())->pluck('name', 'id');
        $result = [];
        foreach ($staff as $uid) {
            $result[$uid] = collect(array_keys($out[$uid] ?? []))->map(fn ($pid) => $names[$pid] ?? '')->filter()->sort()->values()->all();
        }
        // Only people with a hospital role or a roster row are "staff"; others get null.
        $rosterUsers = DB::table('hms_staff_shifts')->where('company_id', $cid)->whereNull('deleted_at')->get(['employee_id', 'doctor_id'])
            ->map(fn ($r) => $r->doctor_id ? $userOfDoctor($r->doctor_id) : ($employees[$r->employee_id] ?? null))->filter()->unique()->all();
        $doctorUsers = $doctors->map(fn ($e) => $employees[$e] ?? null)->filter()->all();

        return array_intersect_key($result, array_flip(array_merge($rosterUsers, $doctorUsers)));
    }

    private function allowed($user): bool
    {
        // The company's admin only (user's rule, 2026-10-25): an admin login whose role has the permission.
        if (! $user || $user->type !== 'admin') {
            return false;
        }
        $perm = Permission::where('name', 'company_logins_view')->first();

        return $perm && $user->hasRole($perm->roles);
    }

    /** bcrypt check, remembered per hash (a new password = a new hash). */
    private function matches(?string $hash, string $plain): bool
    {
        if (! $hash) {
            return false;
        }

        return Cache::rememberForever('login_std:'.sha1($hash.'|'.$plain), fn () => Hash::check($plain, $hash) ? 1 : 0) === 1;
    }
}
