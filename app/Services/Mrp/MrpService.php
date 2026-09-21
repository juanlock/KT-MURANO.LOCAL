<?php

namespace App\Services\Mrp;

use App\Models\MrpBom;
use App\Models\MrpPlanningRun;
use App\Models\MrpPlanningSuggestion;
use App\Models\MrpProductionOrder;
use App\Models\Product;
use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;

/**
 * The planning engine.
 *
 * Classic MRP arithmetic, run level by level:
 *
 *     net = gross demand − on hand − already incoming + safety stock
 *
 * Anything with a net requirement is either MADE (an active BOM exists) or
 * BOUGHT. Making something creates demand for its own components, so the
 * engine recurses — which is exactly where MRP implementations go wrong.
 *
 * Two protections matter and both are here:
 *
 *  1. **Cycle detection.** A BOM that reaches itself — directly, or through
 *     three intermediates — would recurse until the process dies. The path down
 *     the tree is carried on the stack, and a product already on that path is
 *     reported as a loop rather than followed.
 *  2. **Depth limit.** Even an acyclic tree can be pathologically deep through
 *     bad data. Ten levels is far past any real product structure.
 */
class MrpService
{
    private const MAX_DEPTH = 10;

    /** Demand statuses worth planning for — a completed sale needs nothing. */
    private const OPEN_SALE_STATUSES = ['pending'];

    // -------------------------------------------------------- BOM explosion --

    /**
     * Flatten a BOM tree into total component requirements.
     *
     * @param  array  $path  product ids already visited on this branch
     * @return array{requirements: array, loops: array, depth: int}
     */
    public function explode($productId, float $qty, int $level = 0, array $path = []): array
    {
        $productId = (int) $productId;

        if ($level >= self::MAX_DEPTH) {
            return [
                'requirements' => [],
                'loops' => [[
                    'product_id' => $productId,
                    'reason' => 'Structure is more than '.self::MAX_DEPTH.' levels deep — stopped.',
                ]],
                'depth' => $level,
            ];
        }

        // A product that appears twice on one branch is a loop. Following it
        // would never terminate.
        if (in_array($productId, $path, true)) {
            $product = Product::find($productId);

            return [
                'requirements' => [],
                'loops' => [[
                    'product_id' => $productId,
                    'product_name' => $product ? $product->name : ('#'.$productId),
                    'reason' => 'This product is part of its own bill of materials.',
                    'path' => array_merge($path, [$productId]),
                ]],
                'depth' => $level,
            ];
        }

        $bom = MrpBom::defaultFor($productId);
        if (! $bom) {
            return ['requirements' => [], 'loops' => [], 'depth' => $level];
        }

        $bom->load(['lines']);
        $requirements = [];
        $loops = [];
        $maxDepth = $level;

        foreach ($bom->requirementsFor($qty) as $row) {
            $requirements[] = array_merge($row, ['level' => $level + 1, 'bom_id' => $bom->id]);

            // A component with its own BOM is a sub-assembly: recurse into it.
            $child = $this->explode(
                $row['product_id'],
                $row['qty'],
                $level + 1,
                array_merge($path, [$productId])
            );

            $requirements = array_merge($requirements, $child['requirements']);
            $loops = array_merge($loops, $child['loops']);
            $maxDepth = max($maxDepth, $child['depth']);
        }

        return ['requirements' => $requirements, 'loops' => $loops, 'depth' => $maxDepth];
    }

    /**
     * Does adding `componentId` to `bomProductId`'s recipe create a loop?
     *
     * Called before a BOM line is saved, so a cycle is refused at the point it
     * is created rather than discovered later by a planning run that hangs.
     */
    public function wouldCreateCycle(int $bomProductId, int $componentId, array $path = []): bool
    {
        if ($bomProductId === $componentId) {
            return true;
        }
        if (count($path) >= self::MAX_DEPTH) {
            return true;
        }
        if (in_array($componentId, $path, true)) {
            return true;
        }

        $bom = MrpBom::defaultFor($componentId);
        if (! $bom) {
            return false;
        }

        foreach ($bom->lines as $line) {
            if ($this->wouldCreateCycle($bomProductId, (int) $line->product_id, array_merge($path, [$componentId]))) {
                return true;
            }
        }

        return false;
    }

    // --------------------------------------------------------- availability --

    public function onHand(int $productId, ?int $warehouseId): float
    {
        $query = product_warehouse::whereNull('deleted_at')->where('product_id', $productId);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return (float) $query->sum('qte');
    }

    /**
     * Stock already on its way: outstanding purchase order quantities plus the
     * output of production orders that have not closed.
     *
     * Ignoring this is the classic MRP over-ordering bug — you buy again what is
     * already on a lorry.
     */
    public function incoming(int $productId, ?int $warehouseId): float
    {
        $purchased = DB::table('purchase_details')
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->whereNull('purchases.deleted_at')
            ->where('purchase_details.product_id', $productId)
            ->whereIn('purchases.statut', ['pending', 'ordered'])
            ->when($warehouseId, fn ($q) => $q->where('purchases.warehouse_id', $warehouseId))
            ->sum('purchase_details.quantity');

        $manufactured = MrpProductionOrder::whereNull('deleted_at')
            ->where('product_id', $productId)
            ->whereIn('status', ['planned', 'released', 'in_progress'])
            ->when($warehouseId, fn ($q) => $q->where(function ($q) use ($warehouseId) {
                $q->where('fg_warehouse_id', $warehouseId)
                    ->orWhere(function ($q) use ($warehouseId) {
                        $q->whereNull('fg_warehouse_id')->where('warehouse_id', $warehouseId);
                    });
            }))
            ->sum(DB::raw('qty_planned - qty_produced'));

        return round((float) $purchased + (float) $manufactured, 6);
    }

    /** The product's reorder threshold, treated as safety stock. */
    public function safetyStock(int $productId): float
    {
        return (float) (Product::whereKey($productId)->value('stock_alert') ?? 0);
    }

    // ------------------------------------------------------------- planning --

    /**
     * Run the planner.
     *
     * @param  array  $options  ['warehouse_id', 'horizon_start', 'horizon_end',
     *                          'include_safety_stock', 'product_ids']
     */
    public function run(array $options = [], ?int $userId = null): MrpPlanningRun
    {
        $warehouseId = $options['warehouse_id'] ?? null;
        $start = $options['horizon_start'] ?? now()->toDateString();
        $end = $options['horizon_end'] ?? now()->addDays(30)->toDateString();
        $useSafety = ! array_key_exists('include_safety_stock', $options) || $options['include_safety_stock'];

        $run = MrpPlanningRun::create([
            'reference' => MrpPlanningRun::nextReference('MRP'),
            'warehouse_id' => $warehouseId,
            'horizon_start' => $start,
            'horizon_end' => $end,
            'status' => 'running',
            'include_safety_stock' => $useSafety,
            'created_by' => $userId,
        ]);

        try {
            $demand = $this->collectDemand($start, $end, $warehouseId, $options['product_ids'] ?? null);

            // Explode every demanded item, accumulating per product so the same
            // component needed by three parents is planned once, not thrice.
            $gross = [];
            $loops = [];

            foreach ($demand as $productId => $qty) {
                $this->addRequirement($gross, $productId, $qty, 0, null);

                $exploded = $this->explode($productId, $qty);
                $loops = array_merge($loops, $exploded['loops']);

                foreach ($exploded['requirements'] as $row) {
                    $this->addRequirement($gross, $row['product_id'], $row['qty'], $row['level'], $row['bom_id']);
                }
            }

            $make = 0;
            $buy = 0;

            foreach ($gross as $productId => $need) {
                $onHand = $this->onHand($productId, $warehouseId);
                $incoming = $this->incoming($productId, $warehouseId);
                $safety = $useSafety ? $this->safetyStock($productId) : 0.0;

                $net = round($need['qty'] - $onHand - $incoming + $safety, 6);
                if ($net <= 0) {
                    continue;
                }

                $bom = MrpBom::defaultFor($productId);
                $action = $bom ? 'make' : 'buy';
                $action === 'make' ? $make++ : $buy++;

                MrpPlanningSuggestion::create([
                    'planning_run_id' => $run->id,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'action' => $action,
                    'gross_requirement' => round($need['qty'], 4),
                    'on_hand' => round($onHand, 4),
                    'incoming' => round($incoming, 4),
                    'safety_stock' => round($safety, 4),
                    'net_requirement' => $net,
                    // Rounded up to a whole unit: you cannot buy 3.2 boxes.
                    'suggested_qty' => ceil($net),
                    'level' => (int) $need['level'],
                    'bom_id' => $bom ? $bom->id : null,
                    'required_by' => $end,
                    'status' => 'pending',
                ]);
            }

            $run->update([
                'status' => 'completed',
                'demand_lines' => count($demand),
                'make_suggestions' => $make,
                'buy_suggestions' => $buy,
                'last_error' => $loops
                    ? count($loops).' circular bill(s) of materials were skipped: '
                        .implode('; ', array_map(fn ($l) => $l['product_name'] ?? ('#'.$l['product_id']), array_slice($loops, 0, 5)))
                    : null,
            ]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
        }

        return $run->fresh();
    }

    /** Accumulate a requirement, keeping the deepest level it appeared at. */
    private function addRequirement(array &$gross, $productId, float $qty, int $level, $bomId): void
    {
        $productId = (int) $productId;

        if (! isset($gross[$productId])) {
            $gross[$productId] = ['qty' => 0.0, 'level' => $level, 'bom_id' => $bomId];
        }

        $gross[$productId]['qty'] += $qty;
        // The deepest level wins so components are ordered before the
        // assemblies that need them when the list is sorted.
        $gross[$productId]['level'] = max($gross[$productId]['level'], $level);
    }

    /**
     * Demand inside the horizon: quantities on sales that have not completed.
     *
     * @return array<int, float> product id => quantity
     */
    private function collectDemand(string $start, string $end, ?int $warehouseId, ?array $productIds): array
    {
        $rows = DB::table('sale_details')
            ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.statut', self::OPEN_SALE_STATUSES)
            ->whereDate('sales.date', '>=', $start)
            ->whereDate('sales.date', '<=', $end)
            ->when($warehouseId, fn ($q) => $q->where('sales.warehouse_id', $warehouseId))
            ->when($productIds, fn ($q) => $q->whereIn('sale_details.product_id', $productIds))
            ->groupBy('sale_details.product_id')
            ->select('sale_details.product_id', DB::raw('SUM(sale_details.quantity) as qty'))
            ->pluck('qty', 'sale_details.product_id');

        $demand = [];
        foreach ($rows as $productId => $qty) {
            $demand[(int) $productId] = (float) $qty;
        }

        return $demand;
    }

    /**
     * Turn a "make" suggestion into a draft production order.
     *
     * Deliberately draft, not released: a planner reviews and releases. A run
     * that issued stock on its own would be a planning tool with side effects.
     */
    public function acceptSuggestion(MrpPlanningSuggestion $suggestion, ?int $userId = null): array
    {
        if ($suggestion->status !== 'pending') {
            return ['ok' => false, 'message' => 'That suggestion has already been dealt with.'];
        }
        if ($suggestion->action !== 'make') {
            return ['ok' => false, 'message' => 'Only "make" suggestions become production orders. Raise a purchase order for a "buy".'];
        }

        $bom = $suggestion->bom_id ? MrpBom::find($suggestion->bom_id) : MrpBom::defaultFor($suggestion->product_id);
        if (! $bom) {
            return ['ok' => false, 'message' => 'That product no longer has an active bill of materials.'];
        }

        $warehouseId = $suggestion->warehouse_id ?: $bom->warehouse_id;
        if (! $warehouseId) {
            return ['ok' => false, 'message' => 'No warehouse to build in — set one on the bill of materials or the planning run.'];
        }

        $order = null;
        DB::transaction(function () use ($suggestion, $bom, $warehouseId, $userId, &$order) {
            $order = MrpProductionOrder::create([
                'reference' => MrpProductionOrder::nextReference('MO'),
                'bom_id' => $bom->id,
                'product_id' => $suggestion->product_id,
                'product_variant_id' => $suggestion->product_variant_id,
                'qty_planned' => $suggestion->suggested_qty,
                'warehouse_id' => $warehouseId,
                'fg_warehouse_id' => $warehouseId,
                'status' => 'draft',
                'priority' => 'normal',
                'planned_start' => now()->toDateString(),
                'planned_end' => $suggestion->required_by,
                'planning_run_id' => $suggestion->planning_run_id,
                'created_by' => $userId,
                'notes' => 'Raised from planning run '.optional($suggestion->run)->reference,
            ]);

            app(ProductionService::class)->buildFromBom($order, $bom);

            $suggestion->status = 'accepted';
            $suggestion->created_order_id = $order->id;
            $suggestion->save();
        }, 3);

        return ['ok' => true, 'order' => $order ? $order->fresh() : null];
    }
}
