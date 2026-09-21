<?php

namespace App\Http\Controllers;

use App\Models\MrpBom;
use App\Models\MrpBomLine;
use App\Models\MrpBomOperation;
use App\Models\MrpProductionOrder;
use App\Models\MrpWorkCenter;
use App\Models\Product;
use App\Models\Unit;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\Mrp\MrpService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bills of materials and work centres.
 *
 * The one rule worth stating: a component may not contain the assembly it
 * belongs to, at any depth. That is checked when the line is saved rather than
 * discovered later by a planning run that would never terminate.
 */
class MrpBomController extends BaseController
{
    // ------------------------------------------------------------ work centres --

    public function workCenters(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpBom::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $dir = strtolower((string) ($request->SortType ?: 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }
        $sortable = ['id' => 'id', 'code' => 'code', 'name' => 'name', 'hourly_cost' => 'hourly_cost'];
        $order = $sortable[$request->SortField ?? 'id'] ?? 'id';

        $query = MrpWorkCenter::whereNull('deleted_at')
            ->tap(fn ($q) => $this->scopeToWarehouses($q, 'warehouse_id', true))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(fn ($q) => $q->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%"));
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $rows = $query->with('warehouse')->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'warehouse_id' => $c->warehouse_id,
                'warehouse_name' => $c->warehouse ? $c->warehouse->name : null,
                'capacity_per_hour' => round((float) $c->capacity_per_hour, 4),
                'hourly_cost' => round((float) $c->hourly_cost, 4),
                'overhead_rate' => round((float) $c->overhead_rate, 4),
                'efficiency_pct' => (int) $c->efficiency_pct,
                'description' => $c->description,
                'is_active' => (bool) $c->is_active,
                'open_operations' => (int) DB::table('mrp_work_orders')
                    ->where('work_center_id', $c->id)
                    ->whereIn('status', ['pending', 'in_progress'])->count(),
            ]);

        return response()->json(['work_centers' => $rows, 'totalRows' => $totalRows]);
    }

    public function storeWorkCenter(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MrpBom::class);

        $request->validate([
            'code' => 'required|string|max:60|unique:mrp_work_centers,code',
            'name' => 'required|string|max:191',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'efficiency_pct' => 'nullable|integer|min:1|max:200',
            'hourly_cost' => 'nullable|numeric|min:0',
            'overhead_rate' => 'nullable|numeric|min:0',
        ]);


        // A record tied to a warehouse must name one the caller is assigned to;
        // leaving it empty keeps the record company-wide.
        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied($request->warehouse_id);
        }

        MrpWorkCenter::create($this->workCenterPayload($request));

        return response()->json(['success' => true], 200);
    }

    public function updateWorkCenter(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpBom::class);

        $centre = MrpWorkCenter::whereNull('deleted_at')->findOrFail($id);
        if ($centre->warehouse_id) {
            $this->abortIfWarehouseDenied($centre->warehouse_id);
        }

        $request->validate([
            'code' => 'required|string|max:60|unique:mrp_work_centers,code,'.$id,
            'name' => 'required|string|max:191',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'efficiency_pct' => 'nullable|integer|min:1|max:200',
            'hourly_cost' => 'nullable|numeric|min:0',
            'overhead_rate' => 'nullable|numeric|min:0',
        ]);

        $centre->update($this->workCenterPayload($request));

        return response()->json(['success' => true], 200);
    }

    public function destroyWorkCenter(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', MrpBom::class);

        $centre = MrpWorkCenter::whereNull('deleted_at')->findOrFail($id);
        if ($centre->warehouse_id) {
            $this->abortIfWarehouseDenied($centre->warehouse_id);
        }

        // Work already booked against a centre must keep its costing basis.
        $inUse = DB::table('mrp_work_orders')->where('work_center_id', $id)
            ->whereIn('status', ['pending', 'in_progress'])->exists();
        if ($inUse) {
            return response()->json([
                'success' => false,
                'message' => 'That work centre has open work orders. Finish or reassign them first.',
            ], 422);
        }

        $centre->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true], 200);
    }

    private function workCenterPayload(Request $request): array
    {
        return [
            'code' => $request->code,
            'name' => $request->name,
            'warehouse_id' => $request->warehouse_id ?: null,
            'capacity_per_hour' => (float) ($request->capacity_per_hour ?: 0),
            'hourly_cost' => (float) ($request->hourly_cost ?: 0),
            'overhead_rate' => (float) ($request->overhead_rate ?: 0),
            'efficiency_pct' => (int) ($request->efficiency_pct ?: 100),
            'description' => $request->description,
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
        ];
    }

    // -------------------------------------------------------------------- BOMs --

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpBom::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $dir = strtolower((string) ($request->SortType ?: 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }
        $sortable = [
            'id' => 'mrp_boms.id', 'code' => 'mrp_boms.code', 'name' => 'mrp_boms.name',
            'status' => 'mrp_boms.status', 'product_name' => 'products.name',
        ];
        $order = $sortable[$request->SortField ?? 'id'] ?? 'mrp_boms.id';

        $query = MrpBom::leftJoin('products', 'products.id', '=', 'mrp_boms.product_id')
            ->whereNull('mrp_boms.deleted_at')
            ->select('mrp_boms.*', 'products.name as product_name', 'products.code as product_code')
            ->when($request->filled('status'), fn ($q) => $q->where('mrp_boms.status', $request->status))
            ->when($request->filled('product_id'), fn ($q) => $q->where('mrp_boms.product_id', $request->product_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('mrp_boms.name', 'LIKE', "%{$s}%")
                        ->orWhere('mrp_boms.code', 'LIKE', "%{$s}%")
                        ->orWhere('products.name', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $boms = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        $counts = DB::table('mrp_bom_lines')->whereIn('bom_id', $boms->pluck('id'))
            ->groupBy('bom_id')->selectRaw('bom_id, COUNT(*) as c')->pluck('c', 'bom_id');
        $ops = DB::table('mrp_bom_operations')->whereIn('bom_id', $boms->pluck('id'))
            ->groupBy('bom_id')->selectRaw('bom_id, COUNT(*) as c')->pluck('c', 'bom_id');

        $rows = $boms->map(fn ($b) => [
            'id' => $b->id,
            'code' => $b->code,
            'name' => $b->name,
            'product_id' => $b->product_id,
            'product_name' => $b->product_name,
            'product_code' => $b->product_code,
            'output_qty' => round((float) $b->output_qty, 4),
            'version' => (int) $b->version,
            'status' => $b->status,
            'is_default' => (bool) $b->is_default,
            'scrap_pct' => round((float) $b->scrap_pct, 4),
            'overhead_cost' => round((float) $b->overhead_cost, 4),
            'component_count' => (int) ($counts[$b->id] ?? 0),
            'operation_count' => (int) ($ops[$b->id] ?? 0),
        ]);

        return response()->json(['boms' => $rows, 'totalRows' => $totalRows]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpBom::class);

        $bom = MrpBom::whereNull('deleted_at')->with(['lines', 'operations'])->findOrFail($id);

        // A BOM with no warehouse is company-wide; one that names a warehouse
        // is only visible to users assigned to it.
        if ($bom->warehouse_id) {
            $this->abortIfWarehouseDenied($bom->warehouse_id);
        }
        $product = Product::find($bom->product_id);

        $lines = $bom->lines->map(function ($line) {
            $p = Product::find($line->product_id);

            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $p ? $p->name : null,
                'product_code' => $p ? $p->code : null,
                'qty' => round((float) $line->qty, 4),
                'qty_with_scrap' => $line->qtyWithScrap(),
                'unit_id' => $line->unit_id,
                'scrap_pct' => round((float) $line->scrap_pct, 4),
                'is_optional' => (bool) $line->is_optional,
                'unit_cost' => $p ? round((float) $p->cost, 4) : 0,
                'line_cost' => $p ? round((float) $p->cost * $line->qtyWithScrap(), 4) : 0,
                'notes' => $line->notes,
                'sort_order' => (int) $line->sort_order,
            ];
        });

        $operations = $bom->operations->map(function ($op) use ($bom) {
            $centre = $op->workCenter;
            $minutes = $op->minutesFor((float) $bom->output_qty);
            $real = $centre ? $centre->realMinutes($minutes) : $minutes;
            $cost = $centre ? $centre->costFor($real) : ['labour' => 0, 'overhead' => 0];

            return [
                'id' => $op->id,
                'sequence' => (int) $op->sequence,
                'name' => $op->name,
                'work_center_id' => $op->work_center_id,
                'work_center_name' => $centre ? $centre->name : null,
                'setup_minutes' => round((float) $op->setup_minutes, 4),
                'run_minutes_per_unit' => round((float) $op->run_minutes_per_unit, 4),
                'minutes_per_run' => round($real, 2),
                'labour_cost' => $cost['labour'],
                'overhead_cost' => $cost['overhead'],
                'requires_qc' => (bool) $op->requires_qc,
                'instructions' => $op->instructions,
            ];
        });

        $materialCost = round((float) $lines->sum('line_cost'), 4);
        $labourCost = round((float) $operations->sum('labour_cost'), 4);
        $overheadCost = round((float) $operations->sum('overhead_cost') + (float) $bom->overhead_cost, 4);
        $total = round($materialCost + $labourCost + $overheadCost, 4);

        return response()->json([
            'bom' => [
                'id' => $bom->id,
                'code' => $bom->code,
                'name' => $bom->name,
                'product_id' => $bom->product_id,
                'product_name' => $product ? $product->name : null,
                'product_code' => $product ? $product->code : null,
                'output_qty' => round((float) $bom->output_qty, 4),
                'unit_id' => $bom->unit_id,
                'warehouse_id' => $bom->warehouse_id,
                'version' => (int) $bom->version,
                'status' => $bom->status,
                'is_default' => (bool) $bom->is_default,
                'scrap_pct' => round((float) $bom->scrap_pct, 4),
                'overhead_cost' => round((float) $bom->overhead_cost, 4),
                'notes' => $bom->notes,
                'requires_qc' => $bom->requiresQc(),
            ],
            'lines' => $lines,
            'operations' => $operations,
            'costing' => [
                'material' => $materialCost,
                'labour' => $labourCost,
                'overhead' => $overheadCost,
                'total' => $total,
                // What one finished unit costs, which is the figure people
                // actually compare against a selling price.
                'per_unit' => (float) $bom->output_qty > 0 ? round($total / (float) $bom->output_qty, 4) : 0,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MrpBom::class);

        $request->validate([
            'code' => 'required|string|max:60|unique:mrp_boms,code',
            'name' => 'required|string|max:191',
            'product_id' => 'required|exists:products,id',
            'output_qty' => 'required|numeric|min:0.0001',
            'status' => 'nullable|in:draft,active,archived',
            'scrap_pct' => 'nullable|numeric|min:0|max:99',
            'lines' => 'nullable|array',
        ]);
        // A record tied to a warehouse must name one the caller is assigned to;
        // leaving it empty keeps the record company-wide.
        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied($request->warehouse_id);
        }


        $error = $this->validateComponents((int) $request->product_id, $request->lines ?: []);
        if ($error) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $bom = null;
        DB::transaction(function () use ($request, &$bom) {
            $bom = MrpBom::create([
                'code' => $request->code,
                'name' => $request->name,
                'product_id' => $request->product_id,
                'product_variant_id' => $request->product_variant_id ?: null,
                'output_qty' => (float) $request->output_qty,
                'unit_id' => $request->unit_id ?: null,
                'warehouse_id' => $request->warehouse_id ?: null,
                'version' => (int) ($request->version ?: 1),
                'status' => $request->status ?: 'draft',
                'is_default' => $request->boolean('is_default'),
                'scrap_pct' => (float) ($request->scrap_pct ?: 0),
                'overhead_cost' => (float) ($request->overhead_cost ?: 0),
                'notes' => $request->notes,
                'created_by' => optional($request->user('api'))->id,
            ]);

            $this->syncLines($bom, $request->lines ?: []);
            $this->syncOperations($bom, $request->operations ?: []);
            $this->enforceSingleDefault($bom);
        }, 3);

        return response()->json(['success' => true, 'id' => $bom ? $bom->id : null], 200);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MrpBom::class);

        $bom = MrpBom::whereNull('deleted_at')->findOrFail($id);

        $request->validate([
            'code' => 'required|string|max:60|unique:mrp_boms,code,'.$id,
            'name' => 'required|string|max:191',
            'product_id' => 'required|exists:products,id',
            'output_qty' => 'required|numeric|min:0.0001',
            'status' => 'nullable|in:draft,active,archived',
            'scrap_pct' => 'nullable|numeric|min:0|max:99',
        ]);
        // A record tied to a warehouse must name one the caller is assigned to;
        // leaving it empty keeps the record company-wide.
        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied($request->warehouse_id);
        }


        $error = $this->validateComponents((int) $request->product_id, $request->lines ?: [], (int) $id);
        if ($error) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        DB::transaction(function () use ($request, $bom) {
            $bom->update([
                'code' => $request->code,
                'name' => $request->name,
                'product_id' => $request->product_id,
                'product_variant_id' => $request->product_variant_id ?: null,
                'output_qty' => (float) $request->output_qty,
                'unit_id' => $request->unit_id ?: null,
                'warehouse_id' => $request->warehouse_id ?: null,
                'version' => (int) ($request->version ?: $bom->version),
                'status' => $request->status ?: $bom->status,
                'is_default' => $request->boolean('is_default'),
                'scrap_pct' => (float) ($request->scrap_pct ?: 0),
                'overhead_cost' => (float) ($request->overhead_cost ?: 0),
                'notes' => $request->notes,
            ]);

            if ($request->has('lines')) {
                $this->syncLines($bom, $request->lines ?: []);
            }
            if ($request->has('operations')) {
                $this->syncOperations($bom, $request->operations ?: []);
            }
            $this->enforceSingleDefault($bom);
        }, 3);

        return response()->json(['success' => true], 200);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', MrpBom::class);

        $bom = MrpBom::whereNull('deleted_at')->findOrFail($id);

        // An open order still needs the recipe it was built from.
        $inUse = MrpProductionOrder::whereNull('deleted_at')
            ->where('bom_id', $id)
            ->whereIn('status', MrpProductionOrder::OPEN_STATUSES)
            ->exists();

        if ($inUse) {
            return response()->json([
                'success' => false,
                'message' => 'That bill of materials is used by open production orders. Archive it instead.',
            ], 422);
        }

        $bom->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true], 200);
    }

    /** Copy a BOM as the next version — the safe way to change a live recipe. */
    public function duplicate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', MrpBom::class);

        $source = MrpBom::whereNull('deleted_at')->with(['lines', 'operations'])->findOrFail($id);
        if ($source->warehouse_id) {
            $this->abortIfWarehouseDenied($source->warehouse_id);
        }
        $copy = null;

        DB::transaction(function () use ($source, $request, &$copy) {
            $nextVersion = (int) MrpBom::whereNull('deleted_at')
                ->where('product_id', $source->product_id)->max('version') + 1;

            $copy = MrpBom::create([
                'code' => $source->code.'-V'.$nextVersion,
                'name' => $source->name,
                'product_id' => $source->product_id,
                'product_variant_id' => $source->product_variant_id,
                'output_qty' => $source->output_qty,
                'unit_id' => $source->unit_id,
                'warehouse_id' => $source->warehouse_id,
                'version' => $nextVersion,
                // A copy starts as a draft: publishing it is a decision, not a
                // side effect of duplicating.
                'status' => 'draft',
                'is_default' => false,
                'scrap_pct' => $source->scrap_pct,
                'overhead_cost' => $source->overhead_cost,
                'notes' => $source->notes,
                'created_by' => optional($request->user('api'))->id,
            ]);

            foreach ($source->lines as $line) {
                MrpBomLine::create(array_merge($line->only([
                    'product_id', 'product_variant_id', 'qty', 'unit_id',
                    'scrap_pct', 'is_optional', 'notes', 'sort_order',
                ]), ['bom_id' => $copy->id]));
            }

            foreach ($source->operations as $op) {
                MrpBomOperation::create(array_merge($op->only([
                    'sequence', 'name', 'work_center_id', 'setup_minutes',
                    'run_minutes_per_unit', 'requires_qc', 'instructions',
                ]), ['bom_id' => $copy->id]));
            }
        }, 3);

        return response()->json(['success' => true, 'id' => $copy ? $copy->id : null], 200);
    }

    /** Multi-level explosion, for the tree view. */
    public function explode(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpBom::class);

        $bom = MrpBom::whereNull('deleted_at')->findOrFail($id);
        $qty = (float) ($request->qty ?: $bom->output_qty);

        $result = app(MrpService::class)->explode($bom->product_id, $qty);

        $rows = collect($result['requirements'])->map(function ($row) {
            $product = Product::find($row['product_id']);

            return [
                'level' => $row['level'],
                'product_id' => $row['product_id'],
                'product_name' => $product ? $product->name : ('#'.$row['product_id']),
                'product_code' => $product ? $product->code : null,
                'qty' => round($row['qty'], 4),
                'unit_cost' => $product ? round((float) $product->cost, 4) : 0,
                'total_cost' => $product ? round((float) $product->cost * $row['qty'], 4) : 0,
                'is_optional' => $row['is_optional'],
                'has_bom' => (bool) \App\Models\MrpBom::defaultFor($row['product_id']),
            ];
        });

        return response()->json([
            'qty' => $qty,
            'rows' => $rows,
            'loops' => $result['loops'],
            'depth' => $result['depth'],
            'total_cost' => round((float) $rows->sum('total_cost'), 4),
        ]);
    }

    // ----------------------------------------------------------------- internal --

    /**
     * Refuse a component list that would make the product part of its own
     * recipe. Checked here so a cycle can never reach the planning engine.
     */
    private function validateComponents(int $productId, array $lines, ?int $bomId = null): ?string
    {
        $service = app(MrpService::class);
        $seen = [];

        foreach ($lines as $line) {
            $componentId = (int) ($line['product_id'] ?? 0);
            if (! $componentId) {
                continue;
            }

            if ($componentId === $productId) {
                return 'A product cannot be a component of itself.';
            }
            if (in_array($componentId, $seen, true)) {
                $product = Product::find($componentId);

                return 'Component "'.($product ? $product->name : $componentId).'" is listed twice. Combine the quantities instead.';
            }
            $seen[] = $componentId;

            if ($service->wouldCreateCycle($productId, $componentId)) {
                $product = Product::find($componentId);

                return 'Adding "'.($product ? $product->name : $componentId)
                    .'" would create a circular bill of materials — it already contains this product further down.';
            }
        }

        return null;
    }

    private function syncLines(MrpBom $bom, array $lines): void
    {
        $bom->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            if (empty($line['product_id'])) {
                continue;
            }

            MrpBomLine::create([
                'bom_id' => $bom->id,
                'product_id' => $line['product_id'],
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'qty' => (float) ($line['qty'] ?? 0),
                'unit_id' => $line['unit_id'] ?? null,
                'scrap_pct' => (float) ($line['scrap_pct'] ?? 0),
                'is_optional' => ! empty($line['is_optional']),
                'notes' => $line['notes'] ?? null,
                'sort_order' => $i,
            ]);
        }
    }

    private function syncOperations(MrpBom $bom, array $operations): void
    {
        $bom->operations()->delete();

        foreach (array_values($operations) as $i => $op) {
            if (empty($op['name'])) {
                continue;
            }

            MrpBomOperation::create([
                'bom_id' => $bom->id,
                'sequence' => (int) ($op['sequence'] ?? ($i + 1)),
                'name' => $op['name'],
                'work_center_id' => $op['work_center_id'] ?? null,
                'setup_minutes' => (float) ($op['setup_minutes'] ?? 0),
                'run_minutes_per_unit' => (float) ($op['run_minutes_per_unit'] ?? 0),
                'requires_qc' => ! empty($op['requires_qc']),
                'instructions' => $op['instructions'] ?? null,
            ]);
        }
    }

    /** At most one default BOM per product, or "which recipe" has no answer. */
    private function enforceSingleDefault(MrpBom $bom): void
    {
        if (! $bom->is_default) {
            return;
        }

        MrpBom::whereNull('deleted_at')
            ->where('product_id', $bom->product_id)
            ->where('id', '!=', $bom->id)
            ->update(['is_default' => false]);
    }

    /** Select options every manufacturing page shares. */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpBom::class);

        $user = $request->user('api') ?: auth()->user();
        if ($user && $user->is_all_warehouses) {
            $warehouses = Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        } else {
            $ids = UserWarehouse::where('user_id', $user ? $user->id : null)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::whereNull('deleted_at')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
        }

        return response()->json([
            'warehouses' => $warehouses,
            'units' => Unit::whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'ShortName']),
            'work_centers' => MrpWorkCenter::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'code', 'name', 'hourly_cost', 'overhead_rate', 'efficiency_pct'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'label' => $c->code.' — '.$c->name,
                    'hourly_cost' => round((float) $c->hourly_cost, 4),
                    'overhead_rate' => round((float) $c->overhead_rate, 4),
                    'efficiency_pct' => (int) $c->efficiency_pct,
                ]),
            'boms' => MrpBom::whereNull('deleted_at')->where('status', 'active')
                ->orderBy('name')->get(['id', 'code', 'name', 'product_id', 'output_qty', 'warehouse_id'])
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'label' => $b->code.' — '.$b->name,
                    'product_id' => $b->product_id,
                    'output_qty' => round((float) $b->output_qty, 4),
                    'warehouse_id' => $b->warehouse_id,
                ]),
            'employees' => DB::table('employees')->whereNull('deleted_at')
                ->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'hourly_rate'])
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => trim($e->firstname.' '.$e->lastname),
                    'hourly_rate' => round((float) $e->hourly_rate, 4),
                ]),
        ]);
    }

    /** Product search for the component pickers — never the whole catalogue. */
    public function products(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MrpBom::class);

        $search = (string) $request->search;

        $products = Product::whereNull('deleted_at')
            ->where('is_active', 1)
            ->when($search !== '', function ($q) use ($search) {
                return $q->where(fn ($q) => $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'code', 'cost', 'price', 'unit_id', 'stock_alert']);

        return response()->json([
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'label' => $p->code.' — '.$p->name,
                'cost' => round((float) $p->cost, 4),
                'price' => round((float) $p->price, 4),
                'unit_id' => $p->unit_id,
                'stock_alert' => round((float) $p->stock_alert, 4),
            ]),
        ]);
    }
}
