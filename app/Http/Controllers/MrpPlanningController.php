<?php

namespace App\Http\Controllers;

use App\Models\MrpBom;
use App\Models\MrpPlanningRun;
use App\Models\MrpPlanningSuggestion;
use App\Models\MrpProductionOrder;
use App\Models\MrpQualityCheck;
use App\Models\MrpWorkCenter;
use App\Models\MrpWorkOrder;
use App\Models\Product;
use App\Services\Mrp\MrpService;
use App\Services\Mrp\ProductionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Planning runs, the dashboard and the reports.
 *
 * The planner never moves stock and never releases anything: it produces
 * suggestions a human accepts. A planning tool with side effects is one nobody
 * dares run twice.
 */
class MrpPlanningController extends BaseController
{
    private MrpService $mrp;

    public function __construct(MrpService $mrp)
    {
        $this->mrp = $mrp;
    }

    // ------------------------------------------------------------- planning --

    public function run(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MrpPlanningRun::class);

        $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'horizon_start' => 'nullable|date',
            'horizon_end' => 'nullable|date|after_or_equal:horizon_start',
        ]);

        // A planning run over a warehouse reads that warehouse's stock.
        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied($request->warehouse_id);
        }

        @ini_set('max_execution_time', '600');

        $run = $this->mrp->run([
            'warehouse_id' => $request->warehouse_id,
            'horizon_start' => $request->horizon_start ?: now()->toDateString(),
            'horizon_end' => $request->horizon_end ?: now()->addDays(30)->toDateString(),
            'include_safety_stock' => $request->has('include_safety_stock')
                ? $request->boolean('include_safety_stock')
                : true,
        ], optional($request->user('api'))->id);

        return response()->json([
            'success' => $run->status === 'completed',
            'run' => $this->runPayload($run),
        ]);
    }

    public function runs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpPlanningRun::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $query = MrpPlanningRun::query()
            ->tap(fn ($q) => $this->scopeToWarehouses($q, 'warehouse_id', true))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id));

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $rows = $query->with('warehouse')->offset($offSet)->limit($perPage)->orderByDesc('id')->get()
            ->map(fn ($r) => $this->runPayload($r));

        return response()->json(['runs' => $rows, 'totalRows' => $totalRows]);
    }

    private function runPayload(MrpPlanningRun $run): array
    {
        return [
            'id' => $run->id,
            'reference' => $run->reference,
            'warehouse_id' => $run->warehouse_id,
            'warehouse_name' => $run->warehouse ? $run->warehouse->name : 'All warehouses',
            'horizon_start' => $run->horizon_start ? $run->horizon_start->format('Y-m-d') : null,
            'horizon_end' => $run->horizon_end ? $run->horizon_end->format('Y-m-d') : null,
            'status' => $run->status,
            'demand_lines' => (int) $run->demand_lines,
            'make_suggestions' => (int) $run->make_suggestions,
            'buy_suggestions' => (int) $run->buy_suggestions,
            'include_safety_stock' => (bool) $run->include_safety_stock,
            'last_error' => $run->last_error,
            'created_at' => $run->created_at ? $run->created_at->toDateTimeString() : null,
        ];
    }

    public function suggestions(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpPlanningRun::class);

        $perPage = $request->limit ?: 25;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $query = MrpPlanningSuggestion::leftJoin('products', 'products.id', '=', 'mrp_planning_suggestions.product_id')
            ->select('mrp_planning_suggestions.*', 'products.name as product_name', 'products.code as product_code')
            ->when($request->filled('planning_run_id'), fn ($q) => $q->where('planning_run_id', $request->planning_run_id))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('status'), fn ($q) => $q->where('mrp_planning_suggestions.status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(fn ($q) => $q->where('products.name', 'LIKE', "%{$s}%")
                    ->orWhere('products.code', 'LIKE', "%{$s}%"));
            });

        // No run given: show the latest, which is what a planner means by
        // "the suggestions".
        if (! $request->filled('planning_run_id')) {
            $latest = MrpPlanningRun::orderByDesc('id')->value('id');
            $query->where('planning_run_id', $latest ?: 0);
        }

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $rows = $query->offset($offSet)->limit($perPage)
            // Deepest components first: they must be ordered before the
            // assemblies that consume them.
            ->orderByDesc('level')->orderByDesc('net_requirement')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'planning_run_id' => $s->planning_run_id,
                'product_id' => $s->product_id,
                'product_name' => $s->product_name,
                'product_code' => $s->product_code,
                'action' => $s->action,
                'gross_requirement' => round((float) $s->gross_requirement, 4),
                'on_hand' => round((float) $s->on_hand, 4),
                'incoming' => round((float) $s->incoming, 4),
                'safety_stock' => round((float) $s->safety_stock, 4),
                'net_requirement' => round((float) $s->net_requirement, 4),
                'suggested_qty' => round((float) $s->suggested_qty, 4),
                'level' => (int) $s->level,
                'bom_id' => $s->bom_id,
                'required_by' => $s->required_by ? $s->required_by->format('Y-m-d') : null,
                'status' => $s->status,
                'created_order_id' => $s->created_order_id,
            ]);

        return response()->json(['suggestions' => $rows, 'totalRows' => $totalRows]);
    }

    /** Turn a "make" suggestion into a draft production order. */
    public function acceptSuggestion(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', MrpProductionOrder::class);

        $suggestion = MrpPlanningSuggestion::with('run')->findOrFail($id);
        $result = $this->mrp->acceptSuggestion($suggestion, optional($request->user('api'))->id);

        if (empty($result['ok'])) {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        return response()->json([
            'success' => true,
            'order_id' => $result['order'] ? $result['order']->id : null,
            'reference' => $result['order'] ? $result['order']->reference : null,
        ]);
    }

    /** Accept every pending "make" suggestion on a run, deepest level first. */
    public function acceptAll(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MrpProductionOrder::class);

        $request->validate(['planning_run_id' => 'required|exists:mrp_planning_runs,id']);

        $suggestions = MrpPlanningSuggestion::where('planning_run_id', $request->planning_run_id)
            ->where('action', 'make')
            ->where('status', 'pending')
            ->orderByDesc('level')
            ->get();

        $created = 0;
        $failed = [];

        foreach ($suggestions as $suggestion) {
            $result = $this->mrp->acceptSuggestion($suggestion, optional($request->user('api'))->id);
            if (! empty($result['ok'])) {
                $created++;
            } else {
                $failed[] = ['product_id' => $suggestion->product_id, 'message' => $result['message']];
            }
        }

        return response()->json(['success' => true, 'created' => $created, 'failed' => $failed]);
    }

    public function dismissSuggestion(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpPlanningRun::class);

        $suggestion = MrpPlanningSuggestion::findOrFail($id);
        if ($suggestion->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'That suggestion has already been dealt with.'], 422);
        }

        $suggestion->update(['status' => 'dismissed']);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------ dashboard --

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpProductionOrder::class);

        $today = Carbon::today();
        $orders = MrpProductionOrder::whereNull('deleted_at');

        $counts = (clone $orders)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) as planned,
            SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) as released,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        ")->first();

        $late = (clone $orders)
            ->whereIn('status', MrpProductionOrder::OPEN_STATUSES)
            ->whereNotNull('planned_end')
            ->whereDate('planned_end', '<', $today)
            ->count();

        $completedMonth = (clone $orders)->where('status', 'completed')
            ->whereYear('actual_end', $today->year)->whereMonth('actual_end', $today->month);

        $monthStats = (clone $completedMonth)->selectRaw(
            'COALESCE(SUM(qty_produced),0) as produced,
             COALESCE(SUM(qty_scrapped),0) as scrapped,
             COALESCE(SUM(total_cost),0) as cost'
        )->first();

        $produced = (float) ($monthStats->produced ?? 0);
        $scrapped = (float) ($monthStats->scrapped ?? 0);

        // 14-day output, zero-filled so quiet days read as zero not a gap.
        $trendRaw = (clone $orders)->where('status', 'completed')
            ->whereDate('actual_end', '>=', $today->copy()->subDays(13))
            ->groupBy(DB::raw('DATE(actual_end)'))
            ->selectRaw('DATE(actual_end) as d,
                COALESCE(SUM(qty_produced),0) as produced,
                COALESCE(SUM(qty_scrapped),0) as scrapped,
                COALESCE(SUM(total_cost),0) as cost')
            ->get()->keyBy('d');

        $trend = [];
        for ($i = 13; $i >= 0; $i--) {
            $key = $today->copy()->subDays($i)->toDateString();
            $row = $trendRaw->get($key);
            $trend[] = [
                'd' => $key,
                'produced' => round((float) ($row->produced ?? 0), 2),
                'scrapped' => round((float) ($row->scrapped ?? 0), 2),
                'cost' => round((float) ($row->cost ?? 0), 2),
            ];
        }

        $qc = MrpQualityCheck::whereNull('deleted_at')
            ->whereDate('created_at', '>=', $today->copy()->subDays(30))
            ->selectRaw('COALESCE(SUM(qty_inspected),0) as inspected, COALESCE(SUM(qty_rejected),0) as rejected')
            ->first();
        $inspected = (float) ($qc->inspected ?? 0);
        $rejected = (float) ($qc->rejected ?? 0);

        $openWork = MrpWorkOrder::join('mrp_production_orders', 'mrp_production_orders.id', '=', 'mrp_work_orders.production_order_id')
            ->whereNull('mrp_production_orders.deleted_at')
            ->whereIn('mrp_work_orders.status', ['pending', 'in_progress'])
            ->whereIn('mrp_production_orders.status', ['released', 'in_progress'])
            ->count();

        // Which orders cannot start, and what is holding them up.
        $service = app(ProductionService::class);
        $blocked = [];
        foreach ((clone $orders)->whereIn('status', ['draft', 'planned'])->with('materials')->limit(30)->get() as $order) {
            $shortages = collect($service->shortages($order))->reject(fn ($s) => $s['is_optional'])->values();
            if ($shortages->isNotEmpty()) {
                $blocked[] = [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'product_name' => optional($order->product)->name,
                    'short_count' => $shortages->count(),
                    'first_short' => $shortages->first()['product_name'] ?? null,
                ];
            }
            if (count($blocked) >= 8) {
                break;
            }
        }

        $recent = (clone $orders)->with(['product'])->orderByDesc('id')->limit(8)->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'reference' => $o->reference,
                'product_name' => optional($o->product)->name,
                'qty_planned' => round((float) $o->qty_planned, 4),
                'qty_produced' => round((float) $o->qty_produced, 4),
                'status' => $o->status,
                'priority' => $o->priority,
                'progress_pct' => $o->progressPct(),
            ]);

        $byWorkCenter = MrpWorkOrder::join('mrp_work_centers', 'mrp_work_centers.id', '=', 'mrp_work_orders.work_center_id')
            ->whereIn('mrp_work_orders.status', ['pending', 'in_progress'])
            ->groupBy('mrp_work_centers.id', 'mrp_work_centers.name')
            ->selectRaw('mrp_work_centers.name as label, COUNT(*) as count,
                COALESCE(SUM(mrp_work_orders.planned_minutes),0) as minutes')
            ->orderByDesc('minutes')->limit(8)->get()
            ->map(fn ($r) => [
                'label' => $r->label,
                'count' => (int) $r->count,
                'hours' => round((float) $r->minutes / 60, 2),
            ]);

        $latestRun = MrpPlanningRun::orderByDesc('id')->first();

        return response()->json([
            'orders_total' => (int) ($counts->total ?? 0),
            'orders_draft' => (int) ($counts->draft ?? 0),
            'orders_planned' => (int) ($counts->planned ?? 0),
            'orders_released' => (int) ($counts->released ?? 0),
            'orders_in_progress' => (int) ($counts->in_progress ?? 0),
            'orders_completed' => (int) ($counts->completed ?? 0),
            'orders_open' => (int) ($counts->draft ?? 0) + (int) ($counts->planned ?? 0)
                + (int) ($counts->released ?? 0) + (int) ($counts->in_progress ?? 0),
            'orders_late' => $late,
            'open_work_orders' => $openWork,
            'produced_month' => round($produced, 2),
            'scrapped_month' => round($scrapped, 2),
            'scrap_rate_month' => ($produced + $scrapped) > 0
                ? round($scrapped / ($produced + $scrapped) * 100, 2) : null,
            'cost_month' => round((float) ($monthStats->cost ?? 0), 2),
            'qc_inspected_30d' => round($inspected, 2),
            'qc_rejected_30d' => round($rejected, 2),
            'qc_pass_rate_30d' => $inspected > 0 ? round(($inspected - $rejected) / $inspected * 100, 2) : null,
            'work_centers' => (int) MrpWorkCenter::whereNull('deleted_at')->where('is_active', 1)->count(),
            'active_boms' => (int) MrpBom::whereNull('deleted_at')->where('status', 'active')->count(),
            'output_trend' => $trend,
            'load_by_work_center' => $byWorkCenter,
            'blocked_orders' => $blocked,
            'recent_orders' => $recent,
            'latest_run' => $latestRun ? $this->runPayload($latestRun) : null,
            'pending_suggestions' => $latestRun
                ? (int) MrpPlanningSuggestion::where('planning_run_id', $latestRun->id)->where('status', 'pending')->count()
                : 0,
        ]);
    }

    // -------------------------------------------------------------- reports --

    /** Cost and variance, one row per completed order. */
    public function costReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpProductionOrder::class);

        $rows = MrpProductionOrder::leftJoin('products', 'products.id', '=', 'mrp_production_orders.product_id')
            ->whereNull('mrp_production_orders.deleted_at')
            ->where('mrp_production_orders.status', 'completed')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('mrp_production_orders.actual_end', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('mrp_production_orders.actual_end', '<=', $request->to))
            ->when($request->filled('product_id'), fn ($q) => $q->where('mrp_production_orders.product_id', $request->product_id))
            ->tap(fn ($q) => $this->scopeToWarehouses($q, 'mrp_production_orders.warehouse_id'))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('mrp_production_orders.warehouse_id', $request->warehouse_id))
            ->select('mrp_production_orders.*', 'products.name as product_name', 'products.code as product_code')
            ->orderByDesc('mrp_production_orders.actual_end')
            ->limit(1000)
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'reference' => $o->reference,
                'product_name' => $o->product_name,
                'product_code' => $o->product_code,
                'completed_at' => $o->actual_end ? $o->actual_end->format('Y-m-d') : null,
                'qty_planned' => round((float) $o->qty_planned, 4),
                'qty_produced' => round((float) $o->qty_produced, 4),
                'qty_scrapped' => round((float) $o->qty_scrapped, 4),
                'yield_pct' => $o->yieldPct(),
                'scrap_pct' => $o->scrapPct(),
                'material_cost' => round((float) $o->material_cost, 2),
                'labour_cost' => round((float) $o->labour_cost, 2),
                'overhead_cost' => round((float) $o->overhead_cost, 2),
                'total_cost' => round((float) $o->total_cost, 2),
                'unit_cost' => round((float) $o->unit_cost, 4),
                'planned_cost' => round((float) $o->planned_cost, 2),
                'cost_variance' => $o->costVariance(),
                'cost_variance_pct' => $o->costVariancePct(),
            ]);

        return response()->json([
            'rows' => $rows,
            'totals' => [
                'orders' => $rows->count(),
                'produced' => round((float) $rows->sum('qty_produced'), 2),
                'scrapped' => round((float) $rows->sum('qty_scrapped'), 2),
                'material' => round((float) $rows->sum('material_cost'), 2),
                'labour' => round((float) $rows->sum('labour_cost'), 2),
                'overhead' => round((float) $rows->sum('overhead_cost'), 2),
                'total' => round((float) $rows->sum('total_cost'), 2),
                'variance' => round((float) $rows->sum('cost_variance'), 2),
            ],
        ]);
    }

    /** Work-centre efficiency: booked time against allowed time. */
    public function efficiencyReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpProductionOrder::class);

        $rows = MrpWorkOrder::leftJoin('mrp_work_centers', 'mrp_work_centers.id', '=', 'mrp_work_orders.work_center_id')
            ->join('mrp_production_orders', 'mrp_production_orders.id', '=', 'mrp_work_orders.production_order_id')
            ->whereNull('mrp_production_orders.deleted_at')
            ->where('mrp_work_orders.status', 'completed')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('mrp_work_orders.finished_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('mrp_work_orders.finished_at', '<=', $request->to))
            ->groupBy('mrp_work_centers.id', 'mrp_work_centers.name', 'mrp_work_centers.code')
            ->selectRaw("
                mrp_work_centers.id, mrp_work_centers.name, mrp_work_centers.code,
                COUNT(*) as operations,
                COALESCE(SUM(mrp_work_orders.planned_minutes),0) as planned_minutes,
                COALESCE(SUM(mrp_work_orders.actual_minutes),0) as actual_minutes,
                COALESCE(SUM(mrp_work_orders.labour_cost),0) as labour_cost,
                COALESCE(SUM(mrp_work_orders.overhead_cost),0) as overhead_cost,
                COALESCE(SUM(mrp_work_orders.qty_completed),0) as qty_completed,
                COALESCE(SUM(mrp_work_orders.qty_rejected),0) as qty_rejected
            ")
            ->orderByDesc('actual_minutes')
            ->get()
            ->map(function ($r) {
                $planned = (float) $r->planned_minutes;
                $actual = (float) $r->actual_minutes;
                $completed = (float) $r->qty_completed;
                $rejected = (float) $r->qty_rejected;

                return [
                    'id' => $r->id,
                    'name' => $r->name ?: 'Unassigned',
                    'code' => $r->code,
                    'operations' => (int) $r->operations,
                    'planned_hours' => round($planned / 60, 2),
                    'actual_hours' => round($actual / 60, 2),
                    // Over 100% means the centre beat its standard time.
                    'efficiency_pct' => $actual > 0 ? round($planned / $actual * 100, 2) : null,
                    'labour_cost' => round((float) $r->labour_cost, 2),
                    'overhead_cost' => round((float) $r->overhead_cost, 2),
                    'qty_completed' => round($completed, 2),
                    'qty_rejected' => round($rejected, 2),
                    'reject_rate' => ($completed + $rejected) > 0
                        ? round($rejected / ($completed + $rejected) * 100, 2) : null,
                ];
            });

        return response()->json([
            'rows' => $rows,
            'totals' => [
                'operations' => (int) $rows->sum('operations'),
                'planned_hours' => round((float) $rows->sum('planned_hours'), 2),
                'actual_hours' => round((float) $rows->sum('actual_hours'), 2),
                'labour_cost' => round((float) $rows->sum('labour_cost'), 2),
            ],
        ]);
    }

    /** Material consumption: planned against actual, per component. */
    public function materialReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpProductionOrder::class);

        $rows = DB::table('mrp_production_order_materials as m')
            ->join('mrp_production_orders as o', 'o.id', '=', 'm.production_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', ['released', 'in_progress', 'completed'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('o.planned_start', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('o.planned_start', '<=', $request->to))
            ->tap(fn ($q) => $this->scopeToWarehouses($q, 'o.warehouse_id'))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('o.warehouse_id', $request->warehouse_id))
            ->groupBy('p.id', 'p.name', 'p.code')
            ->selectRaw('
                p.id, p.name, p.code,
                COUNT(DISTINCT o.id) as orders,
                COALESCE(SUM(m.qty_required),0) as required,
                COALESCE(SUM(m.qty_issued - m.qty_returned),0) as consumed,
                COALESCE(SUM(m.total_cost),0) as cost
            ')
            ->orderByDesc('cost')
            ->get()
            ->map(function ($r) {
                $required = (float) $r->required;
                $consumed = (float) $r->consumed;

                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'code' => $r->code,
                    'orders' => (int) $r->orders,
                    'required' => round($required, 4),
                    'consumed' => round($consumed, 4),
                    'variance' => round($consumed - $required, 4),
                    // Positive means more was drawn than the recipe called for.
                    'variance_pct' => $required > 0 ? round(($consumed - $required) / $required * 100, 2) : null,
                    'cost' => round((float) $r->cost, 2),
                ];
            });

        return response()->json([
            'rows' => $rows,
            'totals' => [
                'required' => round((float) $rows->sum('required'), 2),
                'consumed' => round((float) $rows->sum('consumed'), 2),
                'cost' => round((float) $rows->sum('cost'), 2),
            ],
        ]);
    }

    /** Quality: pass rates by product. */
    public function qualityReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpQualityCheck::class);

        $rows = MrpQualityCheck::join('mrp_production_orders as o', 'o.id', '=', 'mrp_quality_checks.production_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'o.product_id')
            ->whereNull('mrp_quality_checks.deleted_at')
            ->whereNull('o.deleted_at')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('mrp_quality_checks.checked_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('mrp_quality_checks.checked_at', '<=', $request->to))
            ->groupBy('p.id', 'p.name', 'p.code')
            ->selectRaw("
                p.id, p.name, p.code,
                COUNT(*) as checks,
                SUM(CASE WHEN mrp_quality_checks.status = 'failed' THEN 1 ELSE 0 END) as failed_checks,
                COALESCE(SUM(mrp_quality_checks.qty_inspected),0) as inspected,
                COALESCE(SUM(mrp_quality_checks.qty_passed),0) as passed,
                COALESCE(SUM(mrp_quality_checks.qty_rejected),0) as rejected
            ")
            ->orderByDesc('rejected')
            ->get()
            ->map(function ($r) {
                $inspected = (float) $r->inspected;

                return [
                    'id' => $r->id,
                    'name' => $r->name ?: 'Unknown product',
                    'code' => $r->code,
                    'checks' => (int) $r->checks,
                    'failed_checks' => (int) $r->failed_checks,
                    'inspected' => round($inspected, 2),
                    'passed' => round((float) $r->passed, 2),
                    'rejected' => round((float) $r->rejected, 2),
                    'pass_rate' => $inspected > 0 ? round((float) $r->passed / $inspected * 100, 2) : null,
                ];
            });

        $inspected = (float) $rows->sum('inspected');
        $passed = (float) $rows->sum('passed');

        return response()->json([
            'rows' => $rows,
            'totals' => [
                'checks' => (int) $rows->sum('checks'),
                'inspected' => round($inspected, 2),
                'rejected' => round((float) $rows->sum('rejected'), 2),
                'pass_rate' => $inspected > 0 ? round($passed / $inspected * 100, 2) : null,
            ],
        ]);
    }
}
