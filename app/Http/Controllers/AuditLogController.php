<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings -> Historial de cambios (2026-11-12): the audit_logs of App\Support\AuditTrail. Only the SuperAdmin
 * ("Goku"), whatever a role says: with AdminSystem picked every company, with a company picked that company.
 */
class AuditLogController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request)
    {
        $cid = $this->scope($request);
        $base = DB::table('audit_logs')->when($cid !== null, fn ($q) => $q->where('company_id', $cid));
        $q = (clone $base)
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->from.' 00:00:00'))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->to.' 23:59:59'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->user_id))
            ->when($request->filled('table'), fn ($q) => $q->where('table_name', $request->table))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->event))
            ->when($request->filled('record_id'), fn ($q) => $q->where('record_id', (string) $request->record_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.trim($request->search).'%';
                $q->where(fn ($w) => $w->where('changes', 'like', $s)->orWhere('user_name', 'like', $s)->orWhere('url', 'like', $s));
            });
        $total = (clone $q)->count();
        $per = max(10, min(200, (int) $request->input('per_page', 50)));
        $rows = $q->orderByDesc('id')->forPage(max(1, (int) $request->input('page', 1)), $per)->get();

        $companies = DB::table('companies')->whereIn('id', $rows->pluck('company_id')->filter()->unique())->pluck('name', 'id');
        $admins = DB::table('users')->whereIn('id', $rows->pluck('as_admin_id')->filter()->unique())->pluck('username', 'id');

        return response()->json([
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id, 'created_at' => $r->created_at, 'event' => $r->event, 'table' => $r->table_name,
                'model' => class_basename($r->model), 'record_id' => $r->record_id,
                'user_id' => $r->user_id, 'user_name' => $r->user_name, 'as_admin' => $r->as_admin_id ? ($admins[$r->as_admin_id] ?? '#'.$r->as_admin_id) : null,
                'company_id' => $r->company_id, 'company_name' => $companies[$r->company_id] ?? null,
                'ip' => $r->ip, 'method' => $r->method, 'url' => $r->url,
                'changes' => json_decode((string) $r->changes, true) ?: [],
            ]),
            'total' => $total,
            // Filter choices of this scope (the last 2,000 rows' users / tables are enough to pick from).
            'users' => (clone $base)->whereNotNull('user_id')->select('user_id', 'user_name')->distinct()->orderBy('user_name')->limit(500)->get(),
            'tables' => (clone $base)->select('table_name')->distinct()->orderBy('table_name')->pluck('table_name'),
        ]);
    }

    /** SuperAdmin only. null = every company (AdminSystem), else the picked company. */
    private function scope(Request $request): ?int
    {
        $user = $request->user('api');
        abort_unless($user && $user->type === 'SuperAdmin', 403, 'Only the SuperAdmin can see the change history.');

        return $this->listCompanyId($request);
    }
}
