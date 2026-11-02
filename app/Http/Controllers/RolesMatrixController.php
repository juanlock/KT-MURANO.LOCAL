<?php

namespace App\Http\Controllers;

use App\Support\BusinessTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings -> Roles by company (superadmin/AdminSystem, 2026-11-02): every
 * company (rows) with every role of the install (columns) -- how many users
 * each role has there and how many permissions, so roles nobody uses and
 * companies missing a role stand out. SuperAdmin only.
 */
class RolesMatrixController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user && $user->type === 'SuperAdmin', 403);

        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        $companies = DB::table('companies')->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('id', $tpl))
            ->orderByRaw('id = ? desc', [$tpl])->orderBy('name')->get(['id', 'name', 'is_live']);
        $roles = DB::table('roles')->whereIn('company_id', $companies->pluck('id'))->whereNull('deleted_at')->get(['id', 'company_id', 'name']);
        $users = DB::table('role_user')->join('users', 'users.id', '=', 'role_user.user_id')->whereNull('users.deleted_at')
            ->whereIn('role_user.role_id', $roles->pluck('id'))->selectRaw('role_user.role_id, count(*) n')->groupBy('role_user.role_id')->pluck('n', 'role_id');
        $perms = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', 'not like', 'menu\_hide:%')->whereIn('permission_role.role_id', $roles->pluck('id'))
            ->selectRaw('permission_role.role_id, count(*) n')->groupBy('permission_role.role_id')->pluck('n', 'role_id');

        $byCompany = $roles->groupBy('company_id');
        $rows = $companies->map(function ($c) use ($byCompany, $users, $perms, $tpl) {
            $type = (int) $c->id === $tpl ? null : BusinessTypes::of((int) $c->id);
            $cells = [];
            foreach ($byCompany[$c->id] ?? [] as $r) {
                $cells[$r->name] = ['role_id' => (int) $r->id, 'users' => (int) ($users[$r->id] ?? 0), 'perms' => (int) ($perms[$r->id] ?? 0)];
            }

            return [
                'id' => (int) $c->id, 'name' => (int) $c->id === $tpl ? 'AdminSystem' : $c->name, 'live' => (bool) $c->is_live,
                'type' => $type ? (BusinessTypes::LABELS[$type] ?? $type) : null, 'roles' => $cells,
            ];
        })->values();

        return response()->json(['companies' => $rows, 'role_names' => $roles->pluck('name')->unique()->values()]);
    }
}
