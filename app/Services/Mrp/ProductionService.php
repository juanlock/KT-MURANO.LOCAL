<?php

namespace App\Services\Mrp;

use App\Models\AccountingV2\ChartOfAccount;
use App\Models\AccountingV2\JournalEntry;
use App\Models\AccountingV2\JournalEntryLine;
use App\Models\MrpBom;
use App\Models\MrpProductionOrder;
use App\Models\MrpProductionOrderMaterial;
use App\Models\MrpQualityCheck;
use App\Models\MrpWorkOrder;
use App\Models\Product;
use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;

/**
 * The life of a production order: build it, release it, run it, finish it.
 *
 * Two moments move stock and both are guarded:
 *  - release() consumes raw materials out of the source warehouse
 *  - complete() receives finished goods into the FG warehouse
 *
 * Each happens exactly once, inside a transaction, with the affected stock rows
 * locked. A double-click on "Release" must not issue materials twice, and an
 * order that fails half way through must not leave stock consumed with nothing
 * to show for it.
 */
class ProductionService
{
    /** How the shortage check reports a component that cannot be covered. */
    public const SHORTAGE_NONE = 'ok';

    // ------------------------------------------------------------ building --

    /**
     * Expand a BOM onto an order: material lines and routing steps.
     *
     * Called on create and whenever the quantity changes while the order is
     * still draft or planned. Once released the lines are frozen — they record
     * what was actually issued, and rewriting them would erase that.
     */
    public function buildFromBom(MrpProductionOrder $order, ?MrpBom $bom = null): MrpProductionOrder
    {
        $bom = $bom ?: ($order->bom_id ? MrpBom::with(['lines', 'operations.workCenter'])->find($order->bom_id) : null);

        if (! $bom) {
            return $order;
        }
        if (! in_array($order->status, ['draft', 'planned'], true)) {
            return $order;
        }

        DB::transaction(function () use ($order, $bom) {
            $qty = (float) $order->qty_planned;

            // --- materials ---
            $order->materials()->delete();
            $materialCost = 0.0;

            foreach ($bom->requirementsFor($qty) as $row) {
                $product = Product::find($row['product_id']);
                $unitCost = $product ? (float) $product->cost : 0.0;
                $lineCost = round($unitCost * $row['qty'], 4);
                $materialCost += $lineCost;

                MrpProductionOrderMaterial::create([
                    'production_order_id' => $order->id,
                    'product_id' => $row['product_id'],
                    'product_variant_id' => $row['product_variant_id'],
                    'qty_required' => $row['qty'],
                    'unit_id' => $row['unit_id'],
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineCost,
                    'is_optional' => $row['is_optional'],
                ]);
            }

            // --- routing ---
            $order->workOrders()->delete();
            $labour = 0.0;
            $overhead = 0.0;

            foreach ($bom->operationPlanFor($qty) as $step) {
                $labour += $step['labour_cost'];
                $overhead += $step['overhead_cost'];

                MrpWorkOrder::create([
                    'production_order_id' => $order->id,
                    'bom_operation_id' => $step['bom_operation_id'],
                    'sequence' => $step['sequence'],
                    'name' => $step['name'],
                    'work_center_id' => $step['work_center_id'],
                    'status' => 'pending',
                    'planned_minutes' => $step['planned_minutes'],
                    'labour_cost' => 0,      // filled in as the step is worked
                    'overhead_cost' => 0,
                    'requires_qc' => $step['requires_qc'],
                ]);
            }

            // The plan is what variance is measured against, so it is stored
            // once here and never recomputed from later actuals.
            $order->planned_cost = round($materialCost + $labour + $overhead + (float) $bom->overhead_cost, 4);
            $order->qc_required = $bom->requiresQc();
            $order->save();
        }, 3);

        return $order->fresh(['materials', 'workOrders']);
    }

    // ------------------------------------------------------------ shortages --

    /**
     * What is missing before this order can be released.
     *
     * Optional components are reported but never block: they are optional
     * precisely because production can proceed without them.
     */
    public function shortages(MrpProductionOrder $order): array
    {
        $shortages = [];

        foreach ($order->materials as $material) {
            $available = $this->stockOnHand(
                (int) $material->product_id,
                (int) $order->warehouse_id,
                $material->product_variant_id
            );

            $needed = (float) $material->qty_required - $material->qtyConsumed();
            if ($needed <= 0) {
                continue;
            }

            if ($available + 1e-6 < $needed) {
                $product = Product::find($material->product_id);
                $shortages[] = [
                    'product_id' => (int) $material->product_id,
                    'product_name' => $product ? $product->name : ('#'.$material->product_id),
                    'product_code' => $product ? $product->code : null,
                    'required' => round($needed, 4),
                    'available' => round($available, 4),
                    'short_by' => round($needed - $available, 4),
                    'is_optional' => (bool) $material->is_optional,
                ];
            }
        }

        return $shortages;
    }

    /** Stock on hand for a product (or variant) in one warehouse. */
    public function stockOnHand(int $productId, int $warehouseId, $variantId = null): float
    {
        $query = product_warehouse::whereNull('deleted_at')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId);

        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return (float) ($query->value('qte') ?? 0);
    }

    // ------------------------------------------------------------- release --

    /**
     * Issue materials and put the order on the floor.
     *
     * @param  bool  $allowShortage  proceed even if stock cannot cover the BOM.
     *                               Off by default: issuing more than exists
     *                               drives stock negative, and a negative on-hand
     *                               poisons every valuation report that reads it.
     */
    public function release(MrpProductionOrder $order, bool $allowShortage = false): array
    {
        if ($order->status === 'released' || $order->status === 'in_progress') {
            return ['ok' => false, 'message' => 'This order has already been released.'];
        }
        if ($order->isFinished()) {
            return ['ok' => false, 'message' => 'A '.$order->status.' order cannot be released.'];
        }
        if ((float) $order->qty_planned <= 0) {
            return ['ok' => false, 'message' => 'Set a quantity before releasing this order.'];
        }
        if (! $order->materials()->exists()) {
            return ['ok' => false, 'message' => 'This order has no materials. Attach a bill of materials first.'];
        }

        $blocking = collect($this->shortages($order))->reject(fn ($s) => $s['is_optional'])->values();
        if ($blocking->isNotEmpty() && ! $allowShortage) {
            return [
                'ok' => false,
                'message' => 'Not enough stock for '.$blocking->count().' component(s).',
                'shortages' => $blocking->all(),
            ];
        }

        DB::transaction(function () use ($order) {
            // Re-read under lock: another release may have landed since the
            // checks above ran.
            $locked = MrpProductionOrder::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->materials_issued) {
                throw new \RuntimeException('This order was released by someone else a moment ago.');
            }

            $materialCost = 0.0;

            foreach ($locked->materials as $material) {
                $toIssue = (float) $material->qty_required - $material->qtyConsumed();
                if ($toIssue <= 0) {
                    continue;
                }

                $row = $this->lockStockRow(
                    (int) $material->product_id,
                    (int) $locked->warehouse_id,
                    $material->product_variant_id
                );

                // Cost is captured at issue time, not read later: a price change
                // next month must not rewrite what this batch cost to build.
                $product = Product::find($material->product_id);
                $unitCost = $product ? (float) $product->cost : (float) $material->unit_cost;

                if ($row) {
                    $row->qte = (float) $row->qte - $toIssue;
                    $row->save();
                }

                $material->qty_issued = (float) $material->qty_issued + $toIssue;
                $material->unit_cost = $unitCost;
                $material->total_cost = round($unitCost * $material->qtyConsumed(), 4);
                $material->save();

                $materialCost += (float) $material->total_cost;
            }

            $locked->material_cost = round($materialCost, 4);
            $locked->materials_issued = true;
            $locked->status = 'released';
            $locked->actual_start = $locked->actual_start ?: now();
            $locked->total_cost = round($materialCost + (float) $locked->labour_cost + (float) $locked->overhead_cost, 4);
            $locked->save();
        }, 3);

        return ['ok' => true, 'order' => $order->fresh(['materials'])];
    }

    /** Lock one stock row so two releases cannot read the same figure. */
    private function lockStockRow(int $productId, int $warehouseId, $variantId = null): ?product_warehouse
    {
        $query = product_warehouse::whereNull('deleted_at')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId);

        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->lockForUpdate()->first();
    }

    // ------------------------------------------------------------ complete --

    /**
     * Book the output and close the order.
     *
     * Finished goods are received, costs are totalled, and the unit cost is set
     * from the GOOD quantity only — scrap is absorbed by the units that
     * survived, because spreading cost across units that were thrown away would
     * understate what the sellable stock actually cost.
     */
    public function complete(MrpProductionOrder $order, float $qtyProduced, float $qtyScrapped = 0, array $options = []): array
    {
        if ($order->isFinished()) {
            return ['ok' => false, 'message' => 'This order is already '.$order->status.'.'];
        }
        if (! in_array($order->status, ['released', 'in_progress'], true)) {
            return ['ok' => false, 'message' => 'Release this order before completing it.'];
        }
        if ($qtyProduced < 0 || $qtyScrapped < 0) {
            return ['ok' => false, 'message' => 'Quantities cannot be negative.'];
        }
        if ($qtyProduced + $qtyScrapped <= 0) {
            return ['ok' => false, 'message' => 'Record what came off the line before completing.'];
        }

        // Over-production beyond a sensible tolerance is nearly always a typo,
        // and it silently inflates stock. Refuse unless it is confirmed.
        $planned = (float) $order->qty_planned;
        if ($planned > 0 && $qtyProduced > $planned * 1.5 && empty($options['allow_overproduction'])) {
            return [
                'ok' => false,
                'message' => 'That is more than 150% of the planned quantity. Confirm the over-production to continue.',
                'needs_confirmation' => true,
            ];
        }

        // A required inspection must have happened and passed.
        if ($order->qc_required && empty($options['skip_qc'])) {
            $check = MrpQualityCheck::whereNull('deleted_at')
                ->where('production_order_id', $order->id)
                ->where('type', 'final')
                ->orderByDesc('id')
                ->first();

            if (! $check) {
                return ['ok' => false, 'message' => 'A final quality check is required before this order can be completed.'];
            }
            if ($check->status === 'failed') {
                return ['ok' => false, 'message' => 'The final quality check failed — this batch cannot be received into stock.'];
            }
            if ($check->status === 'pending') {
                return ['ok' => false, 'message' => 'The final quality check has not been completed yet.'];
            }
        }

        $result = null;

        DB::transaction(function () use ($order, $qtyProduced, $qtyScrapped, &$result) {
            $locked = MrpProductionOrder::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->isFinished()) {
                throw new \RuntimeException('This order was completed by someone else a moment ago.');
            }

            // Labour and overhead come from what the floor actually booked.
            $labour = round((float) $locked->workOrders()->sum('labour_cost'), 4);
            $overhead = round((float) $locked->workOrders()->sum('overhead_cost'), 4);
            $bomOverhead = $locked->bom ? (float) $locked->bom->overhead_cost : 0.0;
            $material = round((float) $locked->material_cost, 4);
            $total = round($material + $labour + $overhead + $bomOverhead, 4);

            $fgWarehouse = (int) ($locked->fg_warehouse_id ?: $locked->warehouse_id);

            if ($qtyProduced > 0) {
                $this->receiveFinishedGoods($locked, $fgWarehouse, $qtyProduced);
            }

            $locked->qty_produced = $qtyProduced;
            $locked->qty_scrapped = $qtyScrapped;
            $locked->labour_cost = $labour;
            $locked->overhead_cost = round($overhead + $bomOverhead, 4);
            $locked->total_cost = $total;
            $locked->unit_cost = $qtyProduced > 0 ? round($total / $qtyProduced, 4) : 0;
            $locked->status = 'completed';
            $locked->actual_end = now();
            $locked->save();

            // Any routing step still open is closed out, so a completed order
            // never shows work outstanding.
            $locked->workOrders()->whereIn('status', ['pending', 'in_progress'])
                ->update(['status' => 'skipped', 'updated_at' => now()]);

            $this->postJournalEntry($locked);

            $result = $locked;
        }, 3);

        return ['ok' => true, 'order' => ($result ?: $order)->fresh()];
    }

    /**
     * Add finished goods to stock, creating the warehouse row if the product
     * has never been held there — otherwise the output would vanish.
     */
    private function receiveFinishedGoods(MrpProductionOrder $order, int $warehouseId, float $qty): void
    {
        $row = $this->lockStockRow((int) $order->product_id, $warehouseId, $order->product_variant_id);

        if ($row) {
            $row->qte = (float) $row->qte + $qty;
            $row->save();

            return;
        }

        product_warehouse::create([
            'product_id' => $order->product_id,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $order->product_variant_id,
            'qte' => $qty,
            'manage_stock' => 1,
        ]);
    }

    /**
     * Post the manufacturing entry: finished goods debited, raw materials and
     * the conversion costs credited.
     *
     * Skipped silently when the chart of accounts has no manufacturing accounts
     * set up — a factory that has not configured accounting should still be able
     * to complete an order, and a half-posted entry is worse than none.
     */
    private function postJournalEntry(MrpProductionOrder $order): void
    {
        try {
            $fg = $this->accountId(['finished goods', 'inventory', 'stock']);
            $raw = $this->accountId(['raw material', 'inventory', 'stock']);
            if (! $fg || ! $raw) {
                return;
            }

            $total = (float) $order->total_cost;
            if ($total <= 0) {
                return;
            }

            $entry = JournalEntry::create([
                'date' => now()->toDateString(),
                'description' => 'Production order '.$order->reference,
                'reference_type' => 'mrp_production_order',
                'reference_id' => $order->id,
                'status' => 'posted',
                'posted_at' => now(),
                'created_by' => $order->created_by,
            ]);

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'coa_id' => $fg,
                'debit' => $total,
                'credit' => 0,
                'memo' => 'Finished goods from '.$order->reference,
            ]);
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'coa_id' => $raw,
                'debit' => 0,
                'credit' => $total,
                'memo' => 'Materials and conversion consumed by '.$order->reference,
            ]);

            $order->journal_entry_id = $entry->id;
            $order->save();
        } catch (\Throwable $e) {
            // Accounting must never be the reason a completed batch cannot be
            // recorded; the order stands and the entry can be raised by hand.
        }
    }

    /** First active account whose name matches one of the given hints. */
    private function accountId(array $hints): ?int
    {
        foreach ($hints as $hint) {
            $id = ChartOfAccount::where('is_active', 1)
                ->where('name', 'LIKE', '%'.$hint.'%')
                ->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- cancel --

    /**
     * Cancel an order, returning anything already issued.
     *
     * Materials go back to the warehouse they came from. Without this, a
     * cancelled order permanently loses the stock it had drawn.
     */
    public function cancel(MrpProductionOrder $order, ?string $reason = null): array
    {
        if ($order->isFinished()) {
            return ['ok' => false, 'message' => 'A '.$order->status.' order cannot be cancelled.'];
        }

        DB::transaction(function () use ($order, $reason) {
            $locked = MrpProductionOrder::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->isFinished()) {
                throw new \RuntimeException('This order was already closed.');
            }

            if ($locked->materials_issued) {
                foreach ($locked->materials as $material) {
                    $toReturn = $material->qtyConsumed();
                    if ($toReturn <= 0) {
                        continue;
                    }

                    $row = $this->lockStockRow(
                        (int) $material->product_id,
                        (int) $locked->warehouse_id,
                        $material->product_variant_id
                    );

                    if ($row) {
                        $row->qte = (float) $row->qte + $toReturn;
                        $row->save();
                    }

                    $material->qty_returned = (float) $material->qty_returned + $toReturn;
                    $material->total_cost = 0;
                    $material->save();
                }

                $locked->materials_issued = false;
                $locked->material_cost = 0;
            }

            $locked->status = 'cancelled';
            $locked->actual_end = now();
            $locked->total_cost = 0;
            $locked->unit_cost = 0;
            $locked->notes = trim((string) $locked->notes."\nCancelled: ".($reason ?: 'no reason given'));
            $locked->save();

            $locked->workOrders()->whereIn('status', ['pending', 'in_progress'])
                ->update(['status' => 'skipped', 'updated_at' => now()]);
        }, 3);

        return ['ok' => true, 'order' => $order->fresh()];
    }

    // ---------------------------------------------------------- work orders --

    /** Clock on to a routing step. */
    public function startWorkOrder(MrpWorkOrder $workOrder, $employeeId = null): array
    {
        if ($workOrder->status === 'completed') {
            return ['ok' => false, 'message' => 'That step is already finished.'];
        }

        $order = $workOrder->order;
        if (! $order || ! in_array($order->status, ['released', 'in_progress'], true)) {
            return ['ok' => false, 'message' => 'Release the production order before starting work on it.'];
        }

        $workOrder->status = 'in_progress';
        $workOrder->started_at = $workOrder->started_at ?: now();
        if ($employeeId) {
            $workOrder->employee_id = $employeeId;
        }
        $workOrder->save();

        if ($order->status === 'released') {
            $order->status = 'in_progress';
            $order->actual_start = $order->actual_start ?: now();
            $order->save();
        }

        return ['ok' => true, 'work_order' => $workOrder->fresh()];
    }

    /**
     * Clock off a routing step and book its cost.
     *
     * Elapsed time is taken from the clock when the step was started here;
     * a typed figure is only used when there is no start stamp to measure from.
     */
    public function finishWorkOrder(MrpWorkOrder $workOrder, array $data = []): array
    {
        if ($workOrder->status === 'completed') {
            return ['ok' => false, 'message' => 'That step is already finished.'];
        }

        $workOrder->finished_at = now();

        $measured = $workOrder->elapsedMinutes();
        $typed = isset($data['actual_minutes']) ? (float) $data['actual_minutes'] : null;
        $workOrder->actual_minutes = $measured !== null && $typed === null
            ? $measured
            : (float) ($typed ?? $measured ?? 0);

        $workOrder->qty_completed = (float) ($data['qty_completed'] ?? $workOrder->qty_completed);
        $workOrder->qty_rejected = (float) ($data['qty_rejected'] ?? $workOrder->qty_rejected);
        if (! empty($data['employee_id'])) {
            $workOrder->employee_id = $data['employee_id'];
        }
        if (isset($data['notes'])) {
            $workOrder->notes = $data['notes'];
        }

        $costs = $workOrder->computeCosts();
        $workOrder->labour_cost = $costs['labour'];
        $workOrder->overhead_cost = $costs['overhead'];
        $workOrder->status = 'completed';
        $workOrder->save();

        // Roll the floor's numbers up to the order so its running cost is live.
        $order = $workOrder->order;
        if ($order) {
            $order->labour_cost = round((float) $order->workOrders()->sum('labour_cost'), 4);
            $order->overhead_cost = round((float) $order->workOrders()->sum('overhead_cost'), 4);
            $order->total_cost = round((float) $order->material_cost + $order->labour_cost + $order->overhead_cost, 4);
            $order->save();
        }

        return ['ok' => true, 'work_order' => $workOrder->fresh()];
    }
}
