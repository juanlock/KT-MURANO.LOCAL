<?php

namespace App\Http\Controllers;

use App\Models\KtLog;
use App\Models\Setting;
use App\Support\KtLogSource;
use Illuminate\Http\Request;

/**
 * KT-Log (2026-10-26): the kt_logs history (every request worked with Claude,
 * Protocol Kelsy) as a list with our standard filters and a detail drawer.
 * Install-wide: only the SuperAdmin with AdminSystem (999) picked -- nobody else,
 * whatever their role says (superadmin/<company> neither). Reads production's
 * kt_logs from a developer PC with production access (KtLogSource), read only.
 */
class KtLogController extends Controller
{
    private const LIST_COLUMNS = ['id', 'version', 'log_date', 'location', 'status', 'category', 'company_context', 'summary', 'request_user', 'claude_user', 'created_at'];

    private const SORTABLE = ['id', 'version', 'log_date', 'location', 'status', 'category', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeSystem($request);
        [$conn, $source] = KtLogSource::resolve();
        $q = KtLog::on($conn)
            ->when($request->filled('from'), fn ($q) => $q->whereDate('log_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('log_date', '<=', $request->to))
            ->when($request->filled('location'), fn ($q) => $q->where('location', $request->location))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('claude_user'), fn ($q) => $q->where('claude_user', $request->claude_user))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.trim($request->search).'%';
                $q->where(fn ($w) => $w->where('summary', 'like', $s)->orWhere('request_user', 'like', $s)->orWhere('done', 'like', $s)
                    ->orWhere('files_modified', 'like', $s)->orWhere('company_context', 'like', $s)->orWhere('version', 'like', $s)
                    ->orWhere('id', (int) $request->search));
            });

        $total = (clone $q)->count();
        $limit = max(1, min((int) $request->get('limit', 20), 100000));
        $page = max(1, (int) $request->get('page', 1));
        $sort = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'id';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        return response()->json([
            'logs' => $q->orderBy($sort, $dir)->when($sort !== 'id', fn ($q) => $q->orderBy('id', 'desc'))
                ->offset(($page - 1) * $limit)->limit($limit)->get(self::LIST_COLUMNS),
            'totalRows' => $total,
            'source' => $source, // production | local (KtLogSource)
            // Filter choices, from the data itself.
            'options' => [
                'locations' => KtLog::on($conn)->whereNotNull('location')->distinct()->orderBy('location')->pluck('location'),
                'categories' => KtLog::on($conn)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
                'statuses' => KtLog::on($conn)->whereNotNull('status')->distinct()->orderBy('status')->pluck('status'),
                'users' => KtLog::on($conn)->whereNotNull('claude_user')->distinct()->orderBy('claude_user')->pluck('claude_user'),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeSystem($request);

        return response()->json(['log' => KtLog::on(KtLogSource::connection())->findOrFail($id)]);
    }

    /** superadmin/AdminSystem only: a SuperAdmin with no company or AdminSystem (999) picked. */
    private function authorizeSystem(Request $request): void
    {
        $user = $request->user('api');
        $acting = (int) $request->input('company_id');
        abort_unless($user && $user->type === 'SuperAdmin' && (! $acting || $acting === Setting::TEMPLATE_COMPANY_ID), 403,
            'KT-Log is only for the SuperAdmin working on AdminSystem.');
    }
}
