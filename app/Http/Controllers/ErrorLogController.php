<?php

namespace App\Http\Controllers;

use App\Models\ErrorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErrorLogController extends Controller
{
    public function index(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', ErrorLog::class);

        $perPage = (int) ($request->per_page ?? 10);

        // Filtered base — search, context and date range all narrow the same
        // scope used by the rows, the summary and the charts.
        $base = function () use ($request) {
            return ErrorLog::query()
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->where(function ($qq) use ($s) {
                        $qq->where('context', 'LIKE', "%{$s}%")
                            ->orWhere('message', 'LIKE', "%{$s}%")
                            ->orWhere('details', 'LIKE', "%{$s}%");
                    });
                })
                ->when($request->filled('context'), function ($q) use ($request) {
                    $q->where('context', $request->context);
                })
                ->when($request->filled('from'), function ($q) use ($request) {
                    $q->whereDate('occurred_at', '>=', $request->from);
                })
                ->when($request->filled('to'), function ($q) use ($request) {
                    $q->whereDate('occurred_at', '<=', $request->to);
                });
        };

        $total = $base()->count();

        // ---- Whole-set aggregates (summary + charts) ----------------------
        $topContext = $base()
            ->whereNotNull('context')
            ->groupBy('context')
            ->selectRaw('context, COUNT(*) as c')
            ->orderByDesc('c')
            ->first();
        $summary = [
            'total' => $total,
            'last_24h' => $base()->where('occurred_at', '>=', now()->subDay())->count(),
            'last_7d' => $base()->where('occurred_at', '>=', now()->subDays(7))->count(),
            'top_context' => $topContext->context ?? null,
            'top_context_count' => (int) ($topContext->c ?? 0),
        ];

        $timeseries = $base()
            ->selectRaw('DATE(occurred_at) as d, COUNT(*) as count')
            ->groupByRaw('DATE(occurred_at)')
            ->orderByRaw('DATE(occurred_at)')
            ->get();

        $by_context = $base()
            ->selectRaw("COALESCE(context, '---') as name, COUNT(*) as count")
            ->groupBy('context')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        // Contexts list for the filter dropdown.
        $contexts = ErrorLog::whereNotNull('context')
            ->distinct()
            ->orderBy('context')
            ->pluck('context');
        // --------------------------------------------------------------------

        // Sorting (the page sends SortField/SortType like every report).
        $order = $request->get('SortField', 'occurred_at');
        if (! in_array($order, ['id', 'occurred_at', 'context'], true)) {
            $order = 'occurred_at';
        }
        $dir = strtolower($request->get('SortType', 'desc')) === 'asc' ? 'asc' : 'desc';

        $rowsQ = $base()->orderBy($order, $dir)->orderByDesc('id');

        // per_page = -1 means "everything" (export); paginate(-1) is invalid SQL.
        if ($perPage === -1) {
            $logs = $rowsQ->get();

            return response()->json([
                'logs' => $logs,
                'total' => $total,
                'summary' => $summary,
                'timeseries' => $timeseries,
                'by_context' => $by_context,
                'contexts' => $contexts,
            ]);
        }

        $paginated = $rowsQ->paginate($perPage);

        return response()->json([
            'logs' => $paginated->items(),
            'total' => $paginated->total(),
            'summary' => $summary,
            'timeseries' => $timeseries,
            'by_context' => $by_context,
            'contexts' => $contexts,
        ]);
    }
}
