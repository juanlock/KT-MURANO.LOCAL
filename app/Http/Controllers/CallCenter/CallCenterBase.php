<?php

namespace App\Http\Controllers\CallCenter;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\ModuleFlags;
use Illuminate\Http\Request;

/**
 * Call center screens (2026-11-17): every endpoint works on the acting company (a SuperAdmin's picked company, else the
 * user's own; never AdminSystem 999) with the module on, and checks the screen's permission.
 */
abstract class CallCenterBase extends Controller
{
    use ScopesToCompany;

    protected function allow(Request $request, string ...$perms): void
    {
        foreach ($perms as $perm) {
            if (HelpdeskAccess::can($request->user('api'), $perm)) {
                return;
            }
        }
        abort(403, __('You do not have permission for this.'));
    }

    protected function can(Request $request, string $perm): bool
    {
        return HelpdeskAccess::can($request->user('api'), $perm);
    }

    protected function company(Request $request): int
    {
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, __('Pick a company in the header first.'));
        ModuleFlags::abortUnlessEnabled($cid, 'callcenter');

        return $cid;
    }

    protected function locale(): string
    {
        return app()->getLocale() === 'es' ? 'es' : 'en';
    }

    /** The standard table's paging and sorting (DataTable / useCrudTable). */
    protected function page($q, Request $request, array $sortable, string $default, int $max = 500): array
    {
        $total = (clone $q)->count();
        $per = max(10, min($max, (int) $request->input('limit', 50)));
        $sort = in_array($request->SortField, array_keys($sortable), true) ? $sortable[$request->SortField] : $default;
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';
        $rows = $q->orderBy($sort, $dir)->forPage(max(1, (int) $request->input('page', 1)), $per)->get();

        return [$rows, $total];
    }

    /**
     * Agents of the company: the people (employee logins) whose role has callcenter_work, by their employee name.
     * An admin login (IT Admin, Manager...) only manages the system and is never an agent (user's rule, 2026-11-02).
     */
    protected function agents(int $cid)
    {
        $pid = \Illuminate\Support\Facades\DB::table('permissions')->where('name', 'callcenter_work')->value('id');

        return \Illuminate\Support\Facades\DB::table('users as u')->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('permission_role as pr', 'pr.role_id', '=', 'ru.role_id')
            ->leftJoin('employees as e', fn ($j) => $j->on('e.user_id', '=', 'u.id')->whereNull('e.deleted_at'))
            ->where('u.company_id', $cid)->where('pr.permission_id', $pid)->where('u.statut', 1)->whereNull('u.deleted_at')
            ->whereNotIn('u.type', ['SuperAdmin', 'admin', 'Admin'])
            ->selectRaw("u.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.firstname, e.lastname)), ''), u.username) as username, u.email")
            ->distinct()->orderBy('username')->get();
    }

    /** user id => the person's name (employee name, else the login's username). */
    protected function names(array $userIds): array
    {
        return \Illuminate\Support\Facades\DB::table('users as u')
            ->leftJoin('employees as e', fn ($j) => $j->on('e.user_id', '=', 'u.id')->whereNull('e.deleted_at'))
            ->whereIn('u.id', array_filter(array_unique($userIds)))
            ->selectRaw("u.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.firstname, e.lastname)), ''), u.username) as name")
            ->pluck('name', 'id')->all();
    }
}
