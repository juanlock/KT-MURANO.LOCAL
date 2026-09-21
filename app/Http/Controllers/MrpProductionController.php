<?php

namespace App\Http\Controllers;

use App\Models\MrpBom;
use App\Models\MrpProductionOrder;
use App\Models\MrpQualityCheck;
use App\Models\MrpQualityCheckLine;
use App\Models\MrpWorkOrder;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Notifications\MrpShortageNotification;
use App\Services\Mrp\ProductionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Production orders, shop-floor work orders and quality checks.
 *
 * Every stock-moving action goes through ProductionService, which is the only
 * place that touches product_warehouse. Controllers validate and report; they
 * never move stock themselves.
 */
class MrpProductionController extends BaseController
{
    private ProductionService $production;

    public function __construct(ProductionService $production)
    {
        $this->production = $production;
    }

    // ------------------------------------------------------------------ orders --

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpProductionOrder::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $dir = strtolower((string) ($request->SortType ?: 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }
        $sortable = [
            'id' => 'mrp_production_orders.id',
            'reference' => 'mrp_production_orders.reference',
            'status' => 'mrp_production_orders.status',
            'priority' => 'mrp_production_orders.priority',
            'planned_start' => 'mrp_production_orders.planned_start',
            'qty_planned' => 'mrp_production_orders.qty_planned',
            'total_cost' => 'mrp_production_orders.total_cost',
            'product_name' => 'products.name',
        ];
        $order = $sortable[$request->SortField ?? 'id'] ?? 'mrp_production_orders.id';

        $query = MrpProductionOrder::leftJoin('products', 'products.id', '=', 'mrp_production_orders.product_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'mrp_production_orders.warehouse_id')
            ->whereNull('mrp_production_orders.deleted_at')
            ->tap(fn ($q) => $this->scopeToWarehouses($q, 'mrp_production_orders.warehouse_id'))
            ->select(
                'mrp_production_orders.*',
                'products.name as product_name',
                'products.code as product_code',
                'warehouses.name as warehouse_name'
            )
            ->when($request->filled('status') && $request->status !== 'open',
                fn ($q) => $q->where('mrp_production_orders.status', $request->status))
            ->when($request->status === 'open',
                fn ($q) => $q->whereIn('mrp_production_orders.status', MrpProductionOrder::OPEN_STATUSES))
            ->when($request->filled('priority'), fn ($q) => $q->where('mrp_production_orders.priority', $request->priority))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('mrp_production_orders.warehouse_id', $request->warehouse_id))
            ->when($request->filled('product_id'), fn ($q) => $q->where('mrp_production_orders.product_id', $request->product_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('mrp_production_orders.planned_start', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('mrp_production_orders.planned_start', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('mrp_production_orders.reference', 'LIKE', "%{$s}%")
                        ->orWhere('products.name', 'LIKE', "%{$s}%")
                        ->orWhere('products.code', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = (clone $query)->count();
        $totals = (clone $query)->selectRaw(
            'COALESCE(SUM(mrp_production_orders.total_cost),0) as cost,
             COALESCE(SUM(mrp_production_orders.qty_planned),0) as planned,
             COALESCE(SUM(mrp_production_orders.qty_produced),0) as produced'
        )->first();

        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $orders = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        $progress = DB::table('mrp_work_orders')
            ->whereIn('production_order_id', $orders->pluck('id'))
            ->groupBy('production_order_id')
            ->selectRaw("production_order_id,
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('completed','skipped') THEN 1 ELSE 0 END) as done")
            ->get()->keyBy('production_order_id');

        $rows = $orders->map(function ($o) use ($progress) {
            $p = $progress->get($o->id);
            $pct = $p && $p->total > 0
                ? (int) round($p->done / $p->total * 100)
                : ($o->status === 'completed' ? 100 : 0);

            return [
                'id' => $o->id,
                'reference' => $o->reference,
                'product_id' => $o->product_id,
                'product_name' => $o->product_name,
                'product_code' => $o->product_code,
                'warehouse_id' => $o->warehouse_id,
                'warehouse_name' => $o->warehouse_name,
                'qty_planned' => round((float) $o->qty_planned, 4),
                'qty_produced' => round((float) $o->qty_produced, 4),
                'qty_scrapped' => round((float) $o->qty_scrapped, 4),
                'status' => $o->status,
                'priority' => $o->priority,
                'planned_start' => $o->planned_start ? $o->planned_start->format('Y-m-d') : null,
                'planned_end' => $o->planned_end ? $o->planned_end->format('Y-m-d') : null,
                'material_cost' => round((float) $o->material_cost, 4),
                'labour_cost' => round((float) $o->labour_cost, 4),
                'overhead_cost' => round((float) $o->overhead_cost, 4),
                'total_cost' => round((float) $o->total_cost, 4),
                'unit_cost' => round((float) $o->unit_cost, 4),
                'planned_cost' => round((float) $o->planned_cost, 4),
                'cost_variance' => $o->costVariance(),
                'cost_variance_pct' => $o->costVariancePct(),
                'yield_pct' => $o->yieldPct(),
                'scrap_pct' => $o->scrapPct(),
                'progress_pct' => $pct,
                'materials_issued' => (bool) $o->materials_issued,
                'qc_required' => (bool) $o->qc_required,
                // Late = planned to have finished by now and still not closed.
                'is_late' => $o->isOpen() && $o->planned_end && $o->planned_end->lt(Carbon::today()),
            ];
        });

        return response()->json([
            'orders' => $rows,
            'totalRows' => $totalRows,
            'totals' => [
                'cost' => round((float) ($totals->cost ?? 0), 2),
                'planned' => round((float) ($totals->planned ?? 0), 2),
                'produced' => round((float) ($totals->produced ?? 0), 2),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpProductionOrder::class);

        $order = MrpProductionOrder::whereNull('deleted_at')
            ->with(['materials', 'workOrders.workCenter', 'workOrders.employee', 'qualityChecks.lines', 'bom', 'warehouse', 'product'])
            ->findOrFail($id);

        // A production order consumes and produces stock in its warehouse.
        $this->abortIfWarehouseDenied($order->warehouse_id);

        $materials = $order->materials->map(function ($m) use ($order) {
            $product = Product::find($m->product_id);
            $onHand = $this->production->stockOnHand((int) $m->product_id, (int) $order->warehouse_id, $m->product_variant_id);

            return [
                'id' => $m->id,
                'product_id' => $m->product_id,
                'product_name' => $product ? $product->name : null,
                'product_code' => $product ? $product->code : null,
                'qty_required' => round((float) $m->qty_required, 4),
                'qty_issued' => round((float) $m->qty_issued, 4),
                'qty_returned' => round((float) $m->qty_returned, 4),
                'qty_consumed' => $m->qtyConsumed(),
                'shortfall' => $m->shortfall(),
                'on_hand' => round($onHand, 4),
                'unit_cost' => round((float) $m->unit_cost, 4),
                'total_cost' => round((float) $m->total_cost, 4),
                'is_optional' => (bool) $m->is_optional,
            ];
        });

        $workOrders = $order->workOrders->map(fn ($w) => [
            'id' => $w->id,
            'sequence' => (int) $w->sequence,
            'name' => $w->name,
            'work_center_id' => $w->work_center_id,
            'work_center_name' => $w->workCenter ? $w->workCenter->name : null,
            'employee_id' => $w->employee_id,
            'employee_name' => $w->employee ? trim($w->employee->firstname.' '.$w->employee->lastname) : null,
            'status' => $w->status,
            'planned_minutes' => round((float) $w->planned_minutes, 2),
            'actual_minutes' => round((float) $w->actual_minutes, 2),
            'time_variance_pct' => $w->timeVariancePct(),
            'qty_completed' => round((float) $w->qty_completed, 4),
            'qty_rejected' => round((float) $w->qty_rejected, 4),
            'labour_cost' => round((float) $w->labour_cost, 4),
            'overhead_cost' => round((float) $w->overhead_cost, 4),
            'requires_qc' => (bool) $w->requires_qc,
            'started_at' => $w->started_at ? $w->started_at->toDateTimeString() : null,
            'finished_at' => $w->finished_at ? $w->finished_at->toDateTimeString() : null,
            'notes' => $w->notes,
        ]);

        $checks = $order->qualityChecks->map(fn ($c) => [
            'id' => $c->id,
            'reference' => $c->reference,
            'type' => $c->type,
            'status' => $c->status,
            'qty_inspected' => round((float) $c->qty_inspected, 4),
            'qty_passed' => round((float) $c->qty_passed, 4),
            'qty_rejected' => round((float) $c->qty_rejected, 4),
            'pass_rate' => $c->passRate(),
            'checked_at' => $c->checked_at ? $c->checked_at->toDateTimeString() : null,
            'notes' => $c->notes,
            'lines' => $c->lines->map(fn ($l) => [
                'id' => $l->id,
                'parameter' => $l->parameter,
                'expected' => $l->expected,
                'actual' => $l->actual,
                'result' => $l->result,
                'notes' => $l->notes,
            ]),
        ]);

        $minutes = $order->minutes();

        return response()->json([
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'bom_id' => $order->bom_id,
                'bom_name' => $order->bom ? $order->bom->name : null,
                'product_id' => $order->product_id,
                'product_name' => $order->product ? $order->product->name : null,
                'product_code' => $order->product ? $order->product->code : null,
                'warehouse_id' => $order->warehouse_id,
                'warehouse_name' => $order->warehouse ? $order->warehouse->name : null,
                'fg_warehouse_id' => $order->fg_warehouse_id,
                'qty_planned' => round((float) $order->qty_planned, 4),
                'qty_produced' => round((float) $order->qty_produced, 4),
                'qty_scrapped' => round((float) $order->qty_scrapped, 4),
                'status' => $order->status,
                'priority' => $order->priority,
                'planned_start' => $order->planned_start ? $order->planned_start->format('Y-m-d') : null,
                'planned_end' => $order->planned_end ? $order->planned_end->format('Y-m-d') : null,
                'actual_start' => $order->actual_start ? $order->actual_start->toDateTimeString() : null,
                'actual_end' => $order->actual_end ? $order->actual_end->toDateTimeString() : null,
                'material_cost' => round((float) $order->material_cost, 4),
                'labour_cost' => round((float) $order->labour_cost, 4),
                'overhead_cost' => round((float) $order->overhead_cost, 4),
                'total_cost' => round((float) $order->total_cost, 4),
                'unit_cost' => round((float) $order->unit_cost, 4),
                'planned_cost' => round((float) $order->planned_cost, 4),
                'cost_variance' => $order->costVariance(),
                'cost_variance_pct' => $order->costVariancePct(),
                'yield_pct' => $order->yieldPct(),
                'scrap_pct' => $order->scrapPct(),
                'progress_pct' => $order->progressPct(),
                'planned_minutes' => $minutes['planned'],
                'actual_minutes' => $minutes['actual'],
                'materials_issued' => (bool) $order->materials_issued,
                'qc_required' => (bool) $order->qc_required,
                'journal_entry_id' => $order->journal_entry_id,
                'notes' => $order->notes,
            ],
            'materials' => $materials,
            'work_orders' => $workOrders,
            'quality_checks' => $checks,
            'shortages' => $this->production->shortages($order),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MrpProductionOrder::class);

        $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty_planned' => 'required|numeric|min:0.0001',
            'warehouse_id' => 'required|exists:warehouses,id',
            'bom_id' => 'nullable|exists:mrp_boms,id',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'planned_end' => 'nullable|date|after_or_equal:planned_start',
        ]);

        // The order books stock in this warehouse — it must be the caller's.
        $this->abortIfWarehouseDenied($request->warehouse_id);
        if ($request->filled('fg_warehouse_id')) {
            $this->abortIfWarehouseDenied($request->fg_warehouse_id);
        }

        $bom = $request->bom_id
            ? MrpBom::whereNull('deleted_at')->find($request->bom_id)
            : MrpBom::defaultFor($request->product_id);

        if ($bom && (int) $bom->product_id !== (int) $request->product_id) {
            return response()->json([
                'success' => false,
                'message' => 'That bill of materials builds a different product.',
            ], 422);
        }

        $order = null;
        DB::transaction(function () use ($request, $bom, &$order) {
            $order = MrpProductionOrder::create([
                'reference' => MrpProductionOrder::nextReference('MO'),
                'bom_id' => $bom ? $bom->id : null,
                'product_id' => $request->product_id,
                'product_variant_id' => $request->product_variant_id ?: null,
                'qty_planned' => (float) $request->qty_planned,
                'warehouse_id' => $request->warehouse_id,
                'fg_warehouse_id' => $request->fg_warehouse_id ?: $request->warehouse_id,
                'status' => $request->status ?: 'draft',
                'priority' => $request->priority ?: 'normal',
                'planned_start' => $request->planned_start ?: now()->toDateString(),
                'planned_end' => $request->planned_end,
                'sale_id' => $request->sale_id ?: null,
                'notes' => $request->notes,
                'created_by' => optional($request->user('api'))->id,
            ]);

            if ($bom) {
                $this->production->buildFromBom($order, $bom);
            }
        }, 3);

        return response()->json(['success' => true, 'id' => $order ? $order->id : null], 200);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpProductionOrder::class);

        $order = MrpProductionOrder::whereNull('deleted_at')->findOrFail($id);
        // The order's own warehouse, and any warehouse it is being moved to.
        $this->abortIfWarehouseDenied($order->warehouse_id);
        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied($request->warehouse_id);
        }
        if ($request->filled('fg_warehouse_id')) {
            $this->abortIfWarehouseDenied($request->fg_warehouse_id);
        }

        if ($order->isFinished()) {
            return response()->json([
                'success' => false,
                'message' => 'A '.$order->status.' order can no longer be edited.',
            ], 422);
        }

        $request->validate([
            'qty_planned' => 'required|numeric|min:0.0001',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'planned_end' => 'nullable|date|after_or_equal:planned_start',
        ]);

        // Once material has left the store, changing the quantity would make the
        // issued figures meaningless — the schedule can still move.
        $qtyChanged = abs((float) $request->qty_planned - (float) $order->qty_planned) > 1e-9;
        if ($qtyChanged && $order->materials_issued) {
            return response()->json([
                'success' => false,
                'message' => 'Materials have already been issued, so the quantity is fixed. Cancel the order to start again.',
            ], 422);
        }

        DB::transaction(function () use ($request, $order, $qtyChanged) {
            $order->update([
                'qty_planned' => (float) $request->qty_planned,
                'warehouse_id' => $request->warehouse_id ?: $order->warehouse_id,
                'fg_warehouse_id' => $request->fg_warehouse_id ?: $order->fg_warehouse_id,
                'priority' => $request->priority ?: $order->priority,
                'planned_start' => $request->planned_start ?: $order->planned_start,
                'planned_end' => $request->planned_end,
                'notes' => $request->notes,
            ]);

            // Re-explode so material and routing figures follow the new quantity.
            if ($qtyChanged && $order->bom_id) {
                $this->production->buildFromBom($order->fresh());
            }
        }, 3);

        return response()->json(['success' => true], 200);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', MrpProductionOrder::class);

        $order = MrpProductionOrder::whereNull('deleted_at')->findOrFail($id);

        if ($order->materials_issued) {
            return response()->json([
                'success' => false,
                'message' => 'Materials have been issued against this order. Cancel it instead so the stock is returned.',
            ], 422);
        }

        $order->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true], 200);
    }

    // ------------------------------------------------------------------ actions --

    public function release(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpProductionOrder::class);

        $order = MrpProductionOrder::whereNull('deleted_at')->with('materials')->findOrFail($id);

        try {
            $result = $this->production->release($order, $request->boolean('allow_shortage'));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (empty($result['ok'])) {
            if (! empty($result['shortages'])) {
                $this->notifyShortage($order, $result['shortages']);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'shortages' => $result['shortages'] ?? [],
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Alert whoever can act on a shortage.
     *
     * Sent to users holding the production permission, and never allowed to
     * break the response — a failed notification must not turn a clear "not
     * enough stock" message into a 500.
     */
    private function notifyShortage(MrpProductionOrder $order, array $shortages): void
    {
        try {
            $permission = Permission::where('name', 'mrp_production')->first();
            if (! $permission) {
                return;
            }

            $roleIds = $permission->roles()->pluck('roles.id');
            if ($roleIds->isEmpty()) {
                return;
            }

            $users = User::whereNull('deleted_at')
                ->whereIn('role_id', $roleIds)
                ->limit(50)
                ->get();

            foreach ($users as $user) {
                $user->notify(new MrpShortageNotification($order, $shortages));
            }
        } catch (\Throwable $e) {
            // Swallowed on purpose: the shortage message is what matters.
        }
    }

    public function complete(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpProductionOrder::class);

        $request->validate([
            'qty_produced' => 'required|numeric|min:0',
            'qty_scrapped' => 'nullable|numeric|min:0',
        ]);

        $order = MrpProductionOrder::whereNull('deleted_at')->findOrFail($id);

        try {
            $result = $this->production->complete(
                $order,
                (float) $request->qty_produced,
                (float) ($request->qty_scrapped ?: 0),
                [
                    'allow_overproduction' => $request->boolean('allow_overproduction'),
                    'skip_qc' => $request->boolean('skip_qc'),
                ]
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (empty($result['ok'])) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'needs_confirmation' => $result['needs_confirmation'] ?? false,
            ], 422);
        }

        $order = $result['order'];

        return response()->json([
            'success' => true,
            'unit_cost' => round((float) $order->unit_cost, 4),
            'total_cost' => round((float) $order->total_cost, 4),
            'cost_variance' => $order->costVariance(),
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpProductionOrder::class);

        $order = MrpProductionOrder::whereNull('deleted_at')->with('materials')->findOrFail($id);

        try {
            $result = $this->production->cancel($order, $request->reason);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (empty($result['ok'])) {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        return response()->json(['success' => true]);
    }

    // -------------------------------------------------------------- work orders --

    /** The shop-floor queue. */
    public function workOrders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpProductionOrder::class);

        $perPage = $request->limit ?: 25;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $query = MrpWorkOrder::join('mrp_production_orders', 'mrp_production_orders.id', '=', 'mrp_work_orders.production_order_id')
            ->leftJoin('products', 'products.id', '=', 'mrp_production_orders.product_id')
            ->leftJoin('mrp_work_centers', 'mrp_work_centers.id', '=', 'mrp_work_orders.work_center_id')
            ->leftJoin('employees', 'employees.id', '=', 'mrp_work_orders.employee_id')
            ->whereNull('mrp_production_orders.deleted_at')
            ->select(
                'mrp_work_orders.*',
                'mrp_production_orders.reference as order_reference',
                'mrp_production_orders.status as order_status',
                'mrp_production_orders.qty_planned',
                'products.name as product_name',
                'mrp_work_centers.name as work_center_name',
                'employees.firstname', 'employees.lastname'
            )
            ->when($request->filled('status'), fn ($q) => $q->where('mrp_work_orders.status', $request->status))
            ->when($request->filled('work_center_id'), fn ($q) => $q->where('mrp_work_orders.work_center_id', $request->work_center_id))
            ->when($request->filled('production_order_id'), fn ($q) => $q->where('mrp_work_orders.production_order_id', $request->production_order_id))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('mrp_work_orders.employee_id', $request->employee_id))
            // Only orders actually on the floor, unless asked otherwise.
            ->when(! $request->boolean('include_all'),
                fn ($q) => $q->whereIn('mrp_production_orders.status', ['released', 'in_progress']))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('mrp_work_orders.name', 'LIKE', "%{$s}%")
                        ->orWhere('mrp_production_orders.reference', 'LIKE', "%{$s}%")
                        ->orWhere('products.name', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $rows = $query->offset($offSet)->limit($perPage)
            ->orderBy('mrp_production_orders.id')->orderBy('mrp_work_orders.sequence')
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'production_order_id' => $w->production_order_id,
                'order_reference' => $w->order_reference,
                'order_status' => $w->order_status,
                'product_name' => $w->product_name,
                'sequence' => (int) $w->sequence,
                'name' => $w->name,
                'work_center_id' => $w->work_center_id,
                'work_center_name' => $w->work_center_name,
                'employee_id' => $w->employee_id,
                'employee_name' => trim(($w->firstname ?: '').' '.($w->lastname ?: '')) ?: null,
                'status' => $w->status,
                'planned_minutes' => round((float) $w->planned_minutes, 2),
                'actual_minutes' => round((float) $w->actual_minutes, 2),
                'time_variance_pct' => $w->timeVariancePct(),
                'qty_planned' => round((float) $w->qty_planned, 4),
                'qty_completed' => round((float) $w->qty_completed, 4),
                'qty_rejected' => round((float) $w->qty_rejected, 4),
                'requires_qc' => (bool) $w->requires_qc,
                'started_at' => $w->started_at ? $w->started_at->toDateTimeString() : null,
                'finished_at' => $w->finished_at ? $w->finished_at->toDateTimeString() : null,
                'labour_cost' => round((float) $w->labour_cost, 4),
            ]);

        return response()->json(['work_orders' => $rows, 'totalRows' => $totalRows]);
    }

    public function startWorkOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpProductionOrder::class);

        $workOrder = MrpWorkOrder::with('order')->findOrFail($id);
        $result = $this->production->startWorkOrder($workOrder, $request->employee_id);

        if (empty($result['ok'])) {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        return response()->json(['success' => true]);
    }

    public function finishWorkOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpProductionOrder::class);

        $request->validate([
            'actual_minutes' => 'nullable|numeric|min:0',
            'qty_completed' => 'nullable|numeric|min:0',
            'qty_rejected' => 'nullable|numeric|min:0',
        ]);

        $workOrder = MrpWorkOrder::with(['order', 'workCenter', 'employee'])->findOrFail($id);
        $result = $this->production->finishWorkOrder($workOrder, $request->only([
            'actual_minutes', 'qty_completed', 'qty_rejected', 'employee_id', 'notes',
        ]));

        if (empty($result['ok'])) {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        return response()->json(['success' => true, 'labour_cost' => $result['work_order']->labour_cost]);
    }

    // ---------------------------------------------------------------- quality --

    public function qualityChecks(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpQualityCheck::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $query = MrpQualityCheck::leftJoin('mrp_production_orders', 'mrp_production_orders.id', '=', 'mrp_quality_checks.production_order_id')
            ->leftJoin('products', 'products.id', '=', 'mrp_production_orders.product_id')
            ->leftJoin('users', 'users.id', '=', 'mrp_quality_checks.inspector_id')
            ->whereNull('mrp_quality_checks.deleted_at')
            ->select(
                'mrp_quality_checks.*',
                'mrp_production_orders.reference as order_reference',
                'products.name as product_name',
                'users.firstname', 'users.lastname'
            )
            ->when($request->filled('status'), fn ($q) => $q->where('mrp_quality_checks.status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('mrp_quality_checks.type', $request->type))
            ->when($request->filled('production_order_id'), fn ($q) => $q->where('mrp_quality_checks.production_order_id', $request->production_order_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('mrp_quality_checks.reference', 'LIKE', "%{$s}%")
                        ->orWhere('mrp_production_orders.reference', 'LIKE', "%{$s}%")
                        ->orWhere('products.name', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = (clone $query)->count();
        $totals = (clone $query)->selectRaw(
            'COALESCE(SUM(mrp_quality_checks.qty_inspected),0) as inspected,
             COALESCE(SUM(mrp_quality_checks.qty_rejected),0) as rejected'
        )->first();

        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $rows = $query->offset($offSet)->limit($perPage)->orderByDesc('mrp_quality_checks.id')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'production_order_id' => $c->production_order_id,
                'order_reference' => $c->order_reference,
                'product_name' => $c->product_name,
                'type' => $c->type,
                'status' => $c->status,
                'qty_inspected' => round((float) $c->qty_inspected, 4),
                'qty_passed' => round((float) $c->qty_passed, 4),
                'qty_rejected' => round((float) $c->qty_rejected, 4),
                'pass_rate' => $c->passRate(),
                'inspector_name' => trim(($c->firstname ?: '').' '.($c->lastname ?: '')) ?: null,
                'checked_at' => $c->checked_at ? $c->checked_at->toDateTimeString() : null,
                'notes' => $c->notes,
            ]);

        $inspected = (float) ($totals->inspected ?? 0);
        $rejected = (float) ($totals->rejected ?? 0);

        return response()->json([
            'checks' => $rows,
            'totalRows' => $totalRows,
            'totals' => [
                'inspected' => round($inspected, 2),
                'rejected' => round($rejected, 2),
                'pass_rate' => $inspected > 0 ? round(($inspected - $rejected) / $inspected * 100, 2) : null,
            ],
        ]);
    }

    public function storeQualityCheck(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MrpQualityCheck::class);

        $request->validate([
            'production_order_id' => 'required|exists:mrp_production_orders,id',
            'type' => 'required|in:in_process,final',
            'qty_inspected' => 'required|numeric|min:0',
            'qty_rejected' => 'nullable|numeric|min:0',
        ]);

        $inspected = (float) $request->qty_inspected;
        $rejected = (float) ($request->qty_rejected ?: 0);

        if ($rejected > $inspected) {
            return response()->json([
                'success' => false,
                'message' => 'More units were rejected than were inspected.',
            ], 422);
        }

        $check = null;
        DB::transaction(function () use ($request, $inspected, $rejected, &$check) {
            $check = new MrpQualityCheck([
                'reference' => MrpQualityCheck::nextReference('QC'),
                'production_order_id' => $request->production_order_id,
                'work_order_id' => $request->work_order_id ?: null,
                'type' => $request->type,
                'qty_inspected' => $inspected,
                'qty_passed' => $inspected - $rejected,
                'qty_rejected' => $rejected,
                'inspector_id' => optional($request->user('api'))->id,
                'checked_at' => now(),
                'notes' => $request->notes,
            ]);

            // Status is derived, never taken from the request.
            $check->status = $check->deriveStatus();
            $check->save();

            foreach (array_values($request->lines ?: []) as $i => $line) {
                if (empty($line['parameter'])) {
                    continue;
                }

                MrpQualityCheckLine::create([
                    'quality_check_id' => $check->id,
                    'parameter' => $line['parameter'],
                    'expected' => $line['expected'] ?? null,
                    'actual' => $line['actual'] ?? null,
                    'result' => ($line['result'] ?? 'pass') === 'fail' ? 'fail' : 'pass',
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $i,
                ]);
            }

            // A failed parameter condemns the batch even when the counts look
            // clean — someone recorded a measurement that is out of tolerance.
            $check->load('lines');
            if ($check->hasFailedParameter() && $check->status === 'passed') {
                $check->status = 'partial';
                $check->save();
            }

            $order = MrpProductionOrder::find($request->production_order_id);
            if ($order && $request->type === 'final') {
                $order->qc_passed = in_array($check->status, ['passed', 'partial'], true);
                $order->save();
            }
        }, 3);

        return response()->json(['success' => true, 'id' => $check ? $check->id : null, 'status' => $check ? $check->status : null], 200);
    }

    public function destroyQualityCheck(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', MrpQualityCheck::class);

        $check = MrpQualityCheck::whereNull('deleted_at')->findOrFail($id);
        $check->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true], 200);
    }
}
