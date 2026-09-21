<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetMaintenance;
use App\Models\AssetTransfer;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The read side of Asset Management: the dashboard, the single-asset view,
 * depreciation and the reports.
 *
 * Everything money-related here goes through the Asset model's depreciation
 * methods rather than re-deriving book value in SQL, so a figure on a report
 * always matches the same figure on the asset's own page.
 */
class AssetWorkspaceController extends BaseController
{
    // ------------------------------------------------------------ dashboard --

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Asset::class);

        $today = Carbon::today();
        $soon = $today->copy()->addDays(30);

        // Columns are table-qualified because some of the clones below join
        // asset_categories, which has a deleted_at of its own.
        $live = Asset::whereNull('assets.deleted_at')->whereNull('assets.disposal_date');

        // Assets sit in a warehouse; one with none belongs to the whole company.
        $this->scopeToWarehouses($live, 'assets.warehouse_id', true);

        $counts = (clone $live)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN assets.status = 'in_use' THEN 1 ELSE 0 END) as in_use,
            SUM(CASE WHEN assets.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
            SUM(CASE WHEN assets.status = 'retired' THEN 1 ELSE 0 END) as retired,
            SUM(CASE WHEN assets.assigned_to_id IS NULL THEN 1 ELSE 0 END) as unassigned,
            COALESCE(SUM(assets.purchase_cost), 0) as purchase_value
        ")->first();

        // Book value needs the per-asset method, so it is summed in PHP over the
        // live register rather than in SQL.
        $bookValue = 0.0;
        foreach ((clone $live)->get(['assets.purchase_cost', 'assets.purchase_date', 'assets.salvage_value', 'assets.useful_life_months', 'assets.depreciation_method', 'assets.disposal_date']) as $asset) {
            $bookValue += $asset->bookValue();
        }

        $dueValidation = (clone $live)->whereNotNull('assets.next_validation')
            ->whereDate('assets.next_validation', '<=', $soon)->count();
        $overdueValidation = (clone $live)->whereNotNull('assets.next_validation')
            ->whereDate('assets.next_validation', '<', $today)->count();

        $openMaintenance = AssetMaintenance::whereNull('deleted_at')
            ->whereIn('status', ['scheduled', 'in_progress'])->count();
        $overdueMaintenance = AssetMaintenance::whereNull('deleted_at')
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereDate('scheduled_date', '<', $today)->count();

        $overdueReturns = AssetAssignment::whereNull('deleted_at')
            ->where('status', 'assigned')
            ->whereNotNull('due_back_on')
            ->whereDate('due_back_on', '<', $today)->count();

        $maintenanceYtd = AssetMaintenance::whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereYear('scheduled_date', $today->year)
            ->sum('cost');

        // --- charts ---------------------------------------------------------
        $byCategory = Asset::leftJoin('asset_categories', 'asset_categories.id', '=', 'assets.asset_category_id')
            ->whereNull('assets.deleted_at')
            ->whereNull('assets.disposal_date')
            ->groupBy('asset_categories.id', 'asset_categories.name')
            ->select(
                DB::raw("COALESCE(asset_categories.name, 'Uncategorised') as label"),
                DB::raw('COUNT(assets.id) as count'),
                DB::raw('COALESCE(SUM(assets.purchase_cost),0) as value')
            )
            ->orderByDesc('value')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'label' => $r->label,
                'count' => (int) $r->count,
                'value' => round((float) $r->value, 2),
            ]);

        $byStatus = collect(['in_use', 'maintenance', 'retired'])->map(fn ($s) => [
            'label' => $s,
            'count' => (int) (clone $live)->where('assets.status', $s)->count(),
        ]);

        $byWarehouse = Asset::leftJoin('warehouses', 'warehouses.id', '=', 'assets.warehouse_id')
            ->whereNull('assets.deleted_at')
            ->whereNull('assets.disposal_date')
            ->groupBy('warehouses.id', 'warehouses.name')
            ->select(
                DB::raw("COALESCE(warehouses.name, 'Unassigned') as label"),
                DB::raw('COUNT(assets.id) as count'),
                DB::raw('COALESCE(SUM(assets.purchase_cost),0) as value')
            )
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'label' => $r->label,
                'count' => (int) $r->count,
                'value' => round((float) $r->value, 2),
            ]);

        // Maintenance spend over the last 12 months, zero-filled so the chart
        // shows quiet months instead of skipping them.
        $spendRaw = AssetMaintenance::whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereDate('scheduled_date', '>=', $today->copy()->subMonths(11)->startOfMonth())
            ->groupBy(DB::raw("DATE_FORMAT(scheduled_date, '%Y-%m')"))
            ->select(
                DB::raw("DATE_FORMAT(scheduled_date, '%Y-%m') as d"),
                DB::raw('COALESCE(SUM(cost),0) as cost'),
                DB::raw('COUNT(*) as jobs')
            )
            ->pluck('cost', 'd');

        $spendTrend = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = $today->copy()->subMonths($i)->format('Y-m');
            $spendTrend[] = ['d' => $key, 'cost' => round((float) ($spendRaw[$key] ?? 0), 2)];
        }

        // --- watchlists ------------------------------------------------------
        $upcomingValidations = (clone $live)
            ->leftJoin('asset_categories', 'asset_categories.id', '=', 'assets.asset_category_id')
            ->whereNotNull('assets.next_validation')
            ->whereDate('assets.next_validation', '<=', $soon)
            ->orderBy('assets.next_validation')
            ->limit(8)
            ->get(['assets.id', 'assets.tag', 'assets.name', 'assets.next_validation', 'asset_categories.name as category_name'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'tag' => $a->tag,
                'name' => $a->name,
                'category_name' => $a->category_name,
                'next_validation' => $a->next_validation ? $a->next_validation->format('Y-m-d') : null,
                'days' => $a->daysToValidation(),
            ]);

        $overdueReturnRows = AssetAssignment::leftJoin('assets', 'assets.id', '=', 'asset_assignments.asset_id')
            ->leftJoin('users', 'users.id', '=', 'asset_assignments.user_id')
            ->whereNull('asset_assignments.deleted_at')
            ->where('asset_assignments.status', 'assigned')
            ->whereNotNull('asset_assignments.due_back_on')
            ->whereDate('asset_assignments.due_back_on', '<', $today)
            ->orderBy('asset_assignments.due_back_on')
            ->limit(8)
            ->get([
                'asset_assignments.id', 'asset_assignments.due_back_on', 'asset_assignments.asset_id',
                'assets.tag', 'assets.name', 'users.firstname', 'users.lastname',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'asset_id' => $r->asset_id,
                'tag' => $r->tag,
                'name' => $r->name,
                'user_name' => trim(($r->firstname ?: '').' '.($r->lastname ?: '')),
                'due_back_on' => $r->due_back_on ? Carbon::parse($r->due_back_on)->format('Y-m-d') : null,
                'days_late' => $r->due_back_on ? (int) Carbon::parse($r->due_back_on)->diffInDays($today) : 0,
            ]);

        $openJobs = AssetMaintenance::leftJoin('assets', 'assets.id', '=', 'asset_maintenances.asset_id')
            ->whereNull('asset_maintenances.deleted_at')
            ->whereIn('asset_maintenances.status', ['scheduled', 'in_progress'])
            ->orderBy('asset_maintenances.scheduled_date')
            ->limit(8)
            ->get([
                'asset_maintenances.id', 'asset_maintenances.title', 'asset_maintenances.type',
                'asset_maintenances.status', 'asset_maintenances.scheduled_date', 'asset_maintenances.cost',
                'asset_maintenances.asset_id', 'assets.tag', 'assets.name as asset_name',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'asset_id' => $r->asset_id,
                'tag' => $r->tag,
                'asset_name' => $r->asset_name,
                'title' => $r->title,
                'type' => $r->type,
                'status' => $r->status,
                'cost' => round((float) $r->cost, 2),
                'scheduled_date' => $r->scheduled_date ? Carbon::parse($r->scheduled_date)->format('Y-m-d') : null,
                'is_overdue' => Carbon::parse($r->scheduled_date)->lt($today),
            ]);

        $recent = (clone $live)->orderByDesc('assets.id')->limit(6)
            ->get(['assets.id', 'assets.tag', 'assets.name', 'assets.status', 'assets.purchase_cost', 'assets.purchase_date'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'tag' => $a->tag,
                'name' => $a->name,
                'status' => $a->status,
                'purchase_cost' => round((float) $a->purchase_cost, 2),
                'book_value' => $a->bookValue(),
            ]);

        return response()->json([
            'total' => (int) ($counts->total ?? 0),
            'in_use' => (int) ($counts->in_use ?? 0),
            'maintenance' => (int) ($counts->maintenance ?? 0),
            'retired' => (int) ($counts->retired ?? 0),
            'unassigned' => (int) ($counts->unassigned ?? 0),
            'assigned' => (int) ($counts->total ?? 0) - (int) ($counts->unassigned ?? 0),
            'purchase_value' => round((float) ($counts->purchase_value ?? 0), 2),
            'book_value' => round($bookValue, 2),
            'depreciation_to_date' => round((float) ($counts->purchase_value ?? 0) - $bookValue, 2),
            'due_validation' => $dueValidation,
            'overdue_validation' => $overdueValidation,
            'open_maintenance' => $openMaintenance,
            'overdue_maintenance' => $overdueMaintenance,
            'overdue_returns' => $overdueReturns,
            'maintenance_ytd' => round((float) $maintenanceYtd, 2),
            'by_category' => $byCategory,
            'by_status' => $byStatus,
            'by_warehouse' => $byWarehouse,
            'spend_trend' => $spendTrend,
            'upcoming_validations' => $upcomingValidations,
            'overdue_returns_rows' => $overdueReturnRows,
            'open_jobs' => $openJobs,
            'recent' => $recent,
        ]);
    }

    // ----------------------------------------------------------------- meta --

    /** Select options every asset page shares. */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Asset::class);

        // The api guard is the one the policy check above already used; falling
        // back to auth() keeps this working under the web session too.
        $user = $request->user('api') ?: auth()->user();
        if ($user && $user->is_all_warehouses) {
            $warehouses = Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        } else {
            $ids = UserWarehouse::where('user_id', $user ? $user->id : null)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::whereNull('deleted_at')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
        }

        return response()->json([
            'warehouses' => $warehouses,
            'categories' => AssetCategory::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'assets' => Asset::whereNull('deleted_at')->whereNull('disposal_date')
                ->orderBy('name')->get(['id', 'name', 'tag', 'warehouse_id', 'assigned_to_id'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'tag' => $a->tag,
                    'label' => $a->tag.' — '.$a->name,
                    'warehouse_id' => $a->warehouse_id,
                    'assigned_to_id' => $a->assigned_to_id,
                ]),
            'users' => User::whereNull('deleted_at')->orderBy('firstname')->get(['id', 'firstname', 'lastname'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => trim($u->firstname.' '.$u->lastname),
                ]),
        ]);
    }

    // -------------------------------------------------------------- details --

    /** Everything about one asset, for the details page. */
    public function details(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Asset::class);

        $asset = Asset::leftJoin('asset_categories', 'asset_categories.id', '=', 'assets.asset_category_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'assets.warehouse_id')
            ->leftJoin('users', 'users.id', '=', 'assets.assigned_to_id')
            ->tap(fn ($q) => $this->scopeToWarehouses($q, 'assets.warehouse_id', true))
            ->whereNull('assets.deleted_at')
            ->select(
                'assets.*',
                'asset_categories.name as category_name',
                'warehouses.name as warehouse_name',
                'users.firstname as holder_firstname',
                'users.lastname as holder_lastname'
            )
            ->findOrFail($id);

        $maintenance = AssetMaintenance::where('asset_id', $id)->whereNull('deleted_at')
            ->orderByDesc('scheduled_date')->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'vendor' => $m->vendor,
                'status' => $m->status,
                'cost' => round((float) $m->cost, 2),
                'scheduled_date' => $m->scheduled_date ? $m->scheduled_date->format('Y-m-d') : null,
                'completed_date' => $m->completed_date ? $m->completed_date->format('Y-m-d') : null,
                'next_due_date' => $m->next_due_date ? $m->next_due_date->format('Y-m-d') : null,
                'is_overdue' => $m->isOverdue(),
                'downtime_days' => $m->downtimeDays(),
                'notes' => $m->notes,
            ]);

        $assignments = AssetAssignment::leftJoin('users', 'users.id', '=', 'asset_assignments.user_id')
            ->where('asset_assignments.asset_id', $id)
            ->whereNull('asset_assignments.deleted_at')
            ->orderByDesc('asset_assignments.assigned_on')
            ->select('asset_assignments.*', 'users.firstname', 'users.lastname')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'user_name' => trim(($a->firstname ?: '').' '.($a->lastname ?: '')),
                'assigned_on' => $a->assigned_on ? $a->assigned_on->format('Y-m-d') : null,
                'due_back_on' => $a->due_back_on ? $a->due_back_on->format('Y-m-d') : null,
                'returned_on' => $a->returned_on ? $a->returned_on->format('Y-m-d') : null,
                'condition_out' => $a->condition_out,
                'condition_in' => $a->condition_in,
                'status' => $a->status,
                'is_overdue' => $a->isOverdue(),
                'days_held' => $a->daysHeld(),
            ]);

        $transfers = AssetTransfer::leftJoin('warehouses as from_wh', 'from_wh.id', '=', 'asset_transfers.from_warehouse_id')
            ->leftJoin('warehouses as to_wh', 'to_wh.id', '=', 'asset_transfers.to_warehouse_id')
            ->where('asset_transfers.asset_id', $id)
            ->whereNull('asset_transfers.deleted_at')
            ->orderByDesc('asset_transfers.transfer_date')
            ->select('asset_transfers.*', 'from_wh.name as from_warehouse_name', 'to_wh.name as to_warehouse_name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'from_warehouse_name' => $t->from_warehouse_name,
                'to_warehouse_name' => $t->to_warehouse_name,
                'transfer_date' => $t->transfer_date ? $t->transfer_date->format('Y-m-d') : null,
                'reason' => $t->reason,
                'notes' => $t->notes,
            ]);

        $maintenanceCost = $maintenance->where('status', 'completed')->sum('cost');

        return response()->json([
            'asset' => [
                'id' => $asset->id,
                'tag' => $asset->tag,
                'name' => $asset->name,
                'serial_number' => $asset->serial_number,
                'description' => $asset->description,
                'status' => $asset->status,
                'category_name' => $asset->category_name,
                'warehouse_name' => $asset->warehouse_name,
                'holder_name' => trim(($asset->holder_firstname ?: '').' '.($asset->holder_lastname ?: '')),
                'supplier' => $asset->supplier,
                'purchase_date' => $asset->purchase_date,
                'purchase_cost' => round((float) $asset->purchase_cost, 2),
                'warranty_expiry' => $asset->warranty_expiry ? $asset->warranty_expiry->format('Y-m-d') : null,
                'under_warranty' => $asset->isUnderWarranty(),
                'last_verification' => $asset->last_verification ? $asset->last_verification->format('Y-m-d') : null,
                'next_validation' => $asset->next_validation ? $asset->next_validation->format('Y-m-d') : null,
                'days_to_validation' => $asset->daysToValidation(),
                'depreciation_method' => $asset->depreciation_method,
                'useful_life_months' => $asset->useful_life_months,
                'salvage_value' => round((float) $asset->salvage_value, 2),
                'accumulated_depreciation' => $asset->accumulatedDepreciation(),
                'book_value' => $asset->bookValue(),
                'disposal_date' => $asset->disposal_date ? $asset->disposal_date->format('Y-m-d') : null,
                'disposal_amount' => $asset->disposal_amount ? round((float) $asset->disposal_amount, 2) : null,
                'disposal_note' => $asset->disposal_note,
                'disposal_gain' => $asset->disposalGain(),
                // Purchase + everything spent keeping it alive.
                'total_cost_of_ownership' => round((float) $asset->purchase_cost + (float) $maintenanceCost, 2),
                'maintenance_cost' => round((float) $maintenanceCost, 2),
            ],
            'schedule' => $asset->depreciationSchedule(),
            'maintenance' => $maintenance->values(),
            'assignments' => $assignments,
            'transfers' => $transfers,
        ]);
    }

    /**
     * Retire an asset for good: freezes depreciation at the disposal date and
     * books the gain or loss against what it was still worth.
     */
    public function dispose(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Asset::class);

        $request->validate([
            'disposal_date' => 'required|date',
            'disposal_amount' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $asset = Asset::whereNull('deleted_at')->lockForUpdate()->findOrFail($id);

            if ($asset->disposal_date) {
                return response()->json([
                    'success' => false,
                    'message' => 'This asset has already been disposed of.',
                ], 422);
            }
            if ($asset->purchase_date && Carbon::parse($request->disposal_date)->lt(Carbon::parse($asset->purchase_date))) {
                return response()->json([
                    'success' => false,
                    'message' => 'The disposal date cannot be before the purchase date.',
                ], 422);
            }

            // An asset cannot leave while someone still has it.
            $open = AssetAssignment::where('asset_id', $asset->id)
                ->whereNull('deleted_at')->where('status', 'assigned')->first();
            if ($open) {
                return response()->json([
                    'success' => false,
                    'message' => 'This asset is still checked out. Check it in before disposing of it.',
                ], 422);
            }

            $bookValue = $asset->bookValue($request->disposal_date);

            $asset->update([
                'disposal_date' => $request->disposal_date,
                'disposal_amount' => $request->disposal_amount ?: 0,
                'disposal_note' => $request->disposal_note,
                'status' => 'retired',
                'assigned_to_id' => null,
            ]);

            return response()->json([
                'success' => true,
                'book_value' => $bookValue,
                'gain' => round((float) ($request->disposal_amount ?: 0) - $bookValue, 2),
            ], 200);
        });
    }

    // -------------------------------------------------------------- reports --

    /** Register + book value, one row per asset. Also powers the depreciation page. */
    public function registerReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Asset::class);

        $asOf = $request->filled('as_of') ? Carbon::parse($request->as_of) : Carbon::today();

        $query = Asset::leftJoin('asset_categories', 'asset_categories.id', '=', 'assets.asset_category_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'assets.warehouse_id')
            ->tap(fn ($q) => $this->scopeToWarehouses($q, 'assets.warehouse_id', true))
            ->whereNull('assets.deleted_at')
            ->select('assets.*', 'asset_categories.name as category_name', 'warehouses.name as warehouse_name')
            ->when($request->filled('category_id'), fn ($q) => $q->where('assets.asset_category_id', $request->category_id))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('assets.warehouse_id', $request->warehouse_id))
            ->when($request->filled('status'), fn ($q) => $q->where('assets.status', $request->status))
            ->when($request->filled('method'), fn ($q) => $q->where('assets.depreciation_method', $request->method))
            // Disposed assets are excluded unless explicitly asked for: they are
            // no longer part of the register you are managing.
            ->when(! $request->boolean('include_disposed'), fn ($q) => $q->whereNull('assets.disposal_date'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('assets.name', 'LIKE', "%{$s}%")
                        ->orWhere('assets.tag', 'LIKE', "%{$s}%")
                        ->orWhere('assets.serial_number', 'LIKE', "%{$s}%")
                        ->orWhere('asset_categories.name', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = (clone $query)->count();
        $perPage = $request->limit ?: 10;
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }
        $page = (int) \Request::get('page', 1);

        $assets = (clone $query)->orderBy('assets.name')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        // Maintenance spend per asset, fetched in one query rather than per row.
        $ids = $assets->pluck('id')->all();
        $spend = AssetMaintenance::whereIn('asset_id', $ids)
            ->whereNull('deleted_at')->where('status', 'completed')
            ->groupBy('asset_id')
            ->pluck(DB::raw('COALESCE(SUM(cost),0)'), 'asset_id');

        $rows = $assets->map(function ($a) use ($asOf, $spend) {
            $accumulated = $a->accumulatedDepreciation($asOf);
            $maintenance = round((float) ($spend[$a->id] ?? 0), 2);

            return [
                'id' => $a->id,
                'tag' => $a->tag,
                'name' => $a->name,
                'category_name' => $a->category_name,
                'warehouse_name' => $a->warehouse_name,
                'status' => $a->status,
                'purchase_date' => $a->purchase_date,
                'purchase_cost' => round((float) $a->purchase_cost, 2),
                'method' => $a->depreciation_method,
                'useful_life_months' => $a->useful_life_months,
                'months_depreciated' => $a->monthsDepreciated($asOf),
                'salvage_value' => round((float) $a->salvage_value, 2),
                'accumulated_depreciation' => $accumulated,
                'book_value' => $a->bookValue($asOf),
                'maintenance_cost' => $maintenance,
                'total_cost_of_ownership' => round((float) $a->purchase_cost + $maintenance, 2),
                'disposal_date' => $a->disposal_date ? $a->disposal_date->format('Y-m-d') : null,
                'disposal_gain' => $a->disposalGain(),
            ];
        });

        // Totals cover the whole filtered register, not the page on screen.
        $allTotals = ['purchase' => 0.0, 'accumulated' => 0.0, 'book' => 0.0];
        foreach ((clone $query)->get() as $a) {
            $allTotals['purchase'] += (float) $a->purchase_cost;
            $allTotals['accumulated'] += $a->accumulatedDepreciation($asOf);
            $allTotals['book'] += $a->bookValue($asOf);
        }

        return response()->json([
            'rows' => $rows,
            'totalRows' => $totalRows,
            'as_of' => $asOf->format('Y-m-d'),
            'totals' => [
                'purchase_cost' => round($allTotals['purchase'], 2),
                'accumulated_depreciation' => round($allTotals['accumulated'], 2),
                'book_value' => round($allTotals['book'], 2),
            ],
        ]);
    }

    /** Maintenance spend grouped by asset. */
    public function maintenanceReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Asset::class);

        $rows = AssetMaintenance::leftJoin('assets', 'assets.id', '=', 'asset_maintenances.asset_id')
            ->leftJoin('asset_categories', 'asset_categories.id', '=', 'assets.asset_category_id')
            ->whereNull('asset_maintenances.deleted_at')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('asset_maintenances.scheduled_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('asset_maintenances.scheduled_date', '<=', $request->to))
            ->when($request->filled('category_id'), fn ($q) => $q->where('assets.asset_category_id', $request->category_id))
            ->groupBy('assets.id', 'assets.tag', 'assets.name', 'assets.purchase_cost', 'asset_categories.name')
            ->select(
                'assets.id', 'assets.tag', 'assets.name',
                'asset_categories.name as category_name',
                'assets.purchase_cost',
                DB::raw('COUNT(asset_maintenances.id) as jobs'),
                DB::raw("SUM(CASE WHEN asset_maintenances.status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN asset_maintenances.status IN ('scheduled','in_progress') THEN 1 ELSE 0 END) as open_jobs"),
                DB::raw("COALESCE(SUM(CASE WHEN asset_maintenances.status = 'completed' THEN asset_maintenances.cost ELSE 0 END),0) as spend")
            )
            ->orderByDesc('spend')
            ->get()
            ->map(function ($r) {
                $cost = round((float) $r->purchase_cost, 2);
                $spend = round((float) $r->spend, 2);

                return [
                    'id' => $r->id,
                    'tag' => $r->tag,
                    'name' => $r->name,
                    'category_name' => $r->category_name,
                    'purchase_cost' => $cost,
                    'jobs' => (int) $r->jobs,
                    'completed' => (int) $r->completed,
                    'open_jobs' => (int) $r->open_jobs,
                    'spend' => $spend,
                    // Upkeep as a share of what the asset cost — the number that
                    // tells you when repairing is worse than replacing.
                    'spend_ratio' => $cost > 0 ? round($spend / $cost * 100, 1) : null,
                ];
            });

        return response()->json([
            'rows' => $rows,
            'totals' => [
                'jobs' => (int) $rows->sum('jobs'),
                'open_jobs' => (int) $rows->sum('open_jobs'),
                'spend' => round((float) $rows->sum('spend'), 2),
            ],
        ]);
    }

    /** Who is holding what, and how reliably they bring it back. */
    public function custodyReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Asset::class);

        $today = Carbon::today();

        $rows = AssetAssignment::leftJoin('users', 'users.id', '=', 'asset_assignments.user_id')
            ->whereNull('asset_assignments.deleted_at')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('asset_assignments.assigned_on', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('asset_assignments.assigned_on', '<=', $request->to))
            ->groupBy('users.id', 'users.firstname', 'users.lastname')
            ->select(
                'users.id', 'users.firstname', 'users.lastname',
                DB::raw('COUNT(asset_assignments.id) as total'),
                DB::raw("SUM(CASE WHEN asset_assignments.status = 'assigned' THEN 1 ELSE 0 END) as holding"),
                DB::raw("SUM(CASE WHEN asset_assignments.status = 'returned' THEN 1 ELSE 0 END) as returned"),
                DB::raw("SUM(CASE WHEN asset_assignments.status = 'assigned'
                    AND asset_assignments.due_back_on IS NOT NULL
                    AND asset_assignments.due_back_on < '".$today->toDateString()."' THEN 1 ELSE 0 END) as overdue"),
                DB::raw("SUM(CASE WHEN asset_assignments.status = 'returned'
                    AND asset_assignments.due_back_on IS NOT NULL
                    AND asset_assignments.returned_on > asset_assignments.due_back_on THEN 1 ELSE 0 END) as returned_late")
            )
            ->orderByDesc('holding')
            ->get()
            ->map(function ($r) {
                $returned = (int) $r->returned;
                $late = (int) $r->returned_late;

                return [
                    'id' => $r->id,
                    'user_name' => trim(($r->firstname ?: '').' '.($r->lastname ?: '')),
                    'total' => (int) $r->total,
                    'holding' => (int) $r->holding,
                    'returned' => $returned,
                    'overdue' => (int) $r->overdue,
                    'returned_late' => $late,
                    'on_time_rate' => $returned > 0 ? round(($returned - $late) / $returned * 100, 1) : null,
                ];
            });

        return response()->json([
            'rows' => $rows,
            'totals' => [
                'holding' => (int) $rows->sum('holding'),
                'overdue' => (int) $rows->sum('overdue'),
                'returned' => (int) $rows->sum('returned'),
            ],
        ]);
    }
}
