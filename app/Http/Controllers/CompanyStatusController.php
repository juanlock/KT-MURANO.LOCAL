<?php

namespace App\Http\Controllers;

use App\Support\BusinessTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SuperAdmin -> Company status (user, 2026-11-23: "para mirar el estado de la compañía"): every company of the install
 * with its type, status (production = companies.is_live, else demo; deleted ones on request), its admin login, when it
 * was created, its last data change and last sign-in, and under each one how many records it holds -- sales, purchases,
 * customers... and the total over every table with a company_id (the breakdown by table in the expanded row).
 * superadmin/AdminSystem only (menu / route systemOnly + superAdminOnly, and here by type).
 */
class CompanyStatusController extends Controller
{
    /** Bookkeeping, not the company's information: left out of the totals and of the last change. */
    private const SKIP = ['demo_records', 'menu_positions', 'audit_logs', 'audit_logs_archive', 'login_events', 'login_devices',
        'login_links', 'transport_vehicle_positions', 'b2b_legacy_map', 'password_histories', 'trusted_devices'];

    /** The headline counts shown under each company, in this order. */
    private const MAIN = ['sales', 'purchases', 'clients', 'providers', 'products', 'employees', 'users', 'form_submissions'];

    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user && $user->type === 'SuperAdmin', 403);

        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        $status = (string) $request->input('status', '');
        $companies = DB::table('companies')->where('id', '!=', $tpl)
            ->when($status === 'deleted', fn ($q) => $q->whereNotNull('deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->when($status === 'live', fn ($q) => $q->where('is_live', 1))
            ->when($status === 'demo', fn ($q) => $q->where('is_live', 0))
            ->when(trim((string) $request->input('search', '')) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($request->input('search')).'%'))
            ->get(['id', 'name', 'slug', 'business_type', 'is_live', 'created_at', 'updated_at', 'deleted_at']);
        $ids = $companies->pluck('id')->all();

        // Every table with a company_id: rows and last change per company.
        $counts = [];   // [company][table] = n
        $lastChange = [];
        if ($ids) {
            // One flat information_schema query (a correlated one took 38 s on MariaDB), views left out.
            $db = DB::getDatabaseName();
            $views = collect(DB::select("select table_name t from information_schema.tables where table_schema = ? and table_type <> 'BASE TABLE'", [$db]))->pluck('t')->all();
            $tables = collect(DB::select("select table_name t, group_concat(column_name) c from information_schema.columns
                where table_schema = ? and column_name in ('company_id', 'updated_at') group by table_name", [$db]))
                ->filter(fn ($r) => str_contains($r->c, 'company_id') && ! in_array($r->t, $views, true))
                ->map(fn ($r) => (object) ['t' => $r->t, 'upd' => str_contains($r->c, 'updated_at')]);
            $now = now()->toDateTimeString();
            foreach ($tables as $t) {
                if (in_array($t->t, self::SKIP, true)) {
                    continue;
                }
                // Last change never in the future (the demo sells trips weeks ahead).
                $q = DB::table($t->t)->whereIn('company_id', $ids)->groupBy('company_id')
                    ->selectRaw('company_id, count(*) n'.($t->upd ? ', max(case when updated_at <= ? then updated_at end) u' : ''), $t->upd ? [$now] : []);
                foreach ($q->get() as $r) {
                    $counts[$r->company_id][$t->t] = (int) $r->n;
                    if ($t->upd && $r->u && ($lastChange[$r->company_id] ?? '') < $r->u) {
                        $lastChange[$r->company_id] = $r->u;
                    }
                }
            }
        }
        $demo = $ids ? DB::table('demo_records')->whereIn('company_id', $ids)->groupBy('company_id')->selectRaw('company_id, count(*) n')->pluck('n', 'company_id') : collect();
        // Last successful sign-in (password or WhatsApp link) of any of its logins.
        $lastLogin = $ids ? DB::table('login_events')->whereIn('company_id', $ids)->whereIn('event', ['success', 'link_login'])
            ->groupBy('company_id')->selectRaw('company_id, max(created_at) d')->pluck('d', 'company_id') : collect();

        // The company's admin login(s): type admin, IT Admin first.
        $admins = $ids ? DB::table('users')->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->whereIn('users.company_id', $ids)->where('users.type', 'admin')->whereNull('users.deleted_at')
            ->orderByRaw("roles.name = 'IT Admin' desc")->orderBy('users.id')
            ->get(['users.company_id', 'users.username', 'users.email', 'users.firstname', 'users.lastname', 'users.statut', 'roles.name as role'])
            ->groupBy('company_id') : collect();

        $rows = $companies->map(function ($c) use ($counts, $lastChange, $demo, $lastLogin, $admins) {
            $byTable = $counts[$c->id] ?? [];
            arsort($byTable);
            $adm = collect($admins[$c->id] ?? []);
            $first = $adm->first();
            $type = $c->business_type ?: BusinessTypes::DEFAULT;

            return [
                'id' => (int) $c->id,
                'name' => $c->name,
                'type' => BusinessTypes::LABELS[$type] ?? $type,
                'status' => $c->deleted_at ? 'deleted' : ($c->is_live ? 'live' : 'demo'),
                'admin' => $first ? [
                    'username' => $first->username, 'email' => $first->email, 'role' => $first->role,
                    'name' => trim($first->firstname.' '.$first->lastname), 'active' => (int) $first->statut === 1,
                ] : null,
                'admins' => $adm->map(fn ($a) => ['username' => $a->username, 'email' => $a->email, 'role' => $a->role, 'active' => (int) $a->statut === 1])->values(),
                'created_at' => $c->created_at,
                'last_change' => max(array_filter([$lastChange[$c->id] ?? null, $c->updated_at])) ?: null,
                'last_login' => $lastLogin[$c->id] ?? null,
                'main' => collect(self::MAIN)->mapWithKeys(fn ($t) => [$t => $byTable[$t] ?? 0]),
                'total' => array_sum($byTable),
                'tables' => count($byTable),
                'demo_rows' => (int) ($demo[$c->id] ?? 0),
                'by_table' => collect($byTable)->map(fn ($n, $t) => ['table' => $t, 'n' => $n])->values(),
            ];
        });

        $field = (string) $request->input('SortField', 'name');
        $desc = strtolower((string) $request->input('SortType', 'asc')) === 'desc';
        $allowed = ['name', 'type', 'status', 'created_at', 'last_change', 'last_login', 'total'];
        $field = in_array($field, $allowed, true) ? $field : 'name';
        $rows = $rows->sortBy(fn ($r) => is_string($r[$field] ?? null) ? mb_strtolower($r[$field]) : ($r[$field] ?? ''), SORT_REGULAR, $desc)->values();

        $total = $rows->count();
        $limit = max(1, (int) $request->input('limit', 50));
        $page = max(1, (int) $request->input('page', 1));

        return response()->json([
            'companies' => $rows->slice(($page - 1) * $limit, $limit)->values(),
            'totalRows' => $total,
            'summary' => [
                'live' => $rows->where('status', 'live')->count(),
                'demo' => $rows->where('status', 'demo')->count(),
            ],
        ]);
    }
}
