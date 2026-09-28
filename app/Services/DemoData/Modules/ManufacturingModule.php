<?php

namespace App\Services\DemoData\Modules;

use App\Models\MrpProductionOrder;
use App\Models\MrpQualityCheck;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;

/**
 * Manufacturing (MRP) examples: work centers, bills of materials with lines
 * and routing operations, production orders across the lifecycle (draft ->
 * completed) with their materials and work orders, and quality checks.
 *
 * MRP tables have no company_id -- the SPA scopes them by warehouse -- so
 * every warehouse-bearing row is placed in one of the company's warehouses.
 * Products come from the core demo set, else the company's own products
 * (referenced read-only), else a small demo set created here. Stock is never
 * moved: completed demo orders are history only.
 */
class ManufacturingModule extends DemoModule
{
    private const WORK_CENTERS = [
        ['Assembly Line', 12, 18.5, 4],
        ['Cutting Station', 20, 22.0, 5],
        ['Finishing & Painting', 8, 16.0, 3],
        ['Packaging Bench', 30, 12.0, 2],
    ];
    private const OPERATIONS = [
        ['Cut and prepare parts', 1, 10, 2.5, false],
        ['Assemble components', 0, 15, 6.0, false],
        ['Finish and inspect', 2, 5, 3.0, true],
        ['Pack for storage', 3, 5, 1.0, false],
    ];
    private const QC_PARAMETERS = [
        ['Dimensions within tolerance', '+/- 2 mm'],
        ['Surface finish', 'No scratches'],
        ['Fasteners secured', 'All tight'],
        ['Label and packaging', 'Correct SKU'],
    ];

    public function key(): string
    {
        return 'manufacturing';
    }

    public function label(): string
    {
        return 'Manufacturing (Work centers, BOMs, Production orders, Quality)';
    }

    public function dataTables(): array
    {
        return ['mrp_work_centers', 'mrp_boms', 'mrp_production_orders'];
    }

    public function generate(DemoContext $ctx): array
    {
        $warehouseId = $ctx->warehouseIds[0];
        $employeeIds = $this->employeeIds($ctx);
        $products = $this->products($ctx);
        if (count($products) < 3) {
            return [];
        }

        // ---- Work centers
        $centerIds = [];
        foreach (self::WORK_CENTERS as $i => [$name, $cap, $rate, $overhead]) {
            $centerIds[] = $ctx->insert('mrp_work_centers', [
                'code' => 'WC-'.strtoupper(substr(str_replace(' ', '', $name), 0, 4)).'-'.random_int(1000, 9999),
                'name' => $name,
                'warehouse_id' => $warehouseId,
                'capacity_per_hour' => $cap,
                'hourly_cost' => $rate,
                'overhead_rate' => $overhead,
                'efficiency_pct' => $ctx->pick([85, 90, 95, 100]),
                'description' => 'Demo data',
                'is_active' => 1,
            ]);
        }

        // ---- BOMs: the first two products are finished goods, the rest components.
        $finished = array_slice($products, 0, 2);
        $components = array_slice($products, 2);
        $boms = [];
        $lineCount = 0;
        $opCount = 0;
        foreach ($finished as $bi => $fg) {
            $bomId = $ctx->insert('mrp_boms', [
                'code' => 'BOM-'.random_int(10000, 99999),
                'name' => $fg->name.' - Standard',
                'product_id' => $fg->id,
                'output_qty' => 1,
                'unit_id' => $fg->unit_id,
                'warehouse_id' => $warehouseId,
                'version' => 1,
                'status' => 'active',
                'is_default' => 1,
                'scrap_pct' => $ctx->pick([0, 2, 5]),
                'overhead_cost' => $ctx->pick([2.5, 4, 6]),
                'notes' => 'Demo data',
                'created_by' => $ctx->userId,
            ]);
            $lines = [];
            $picked = array_slice($components, $bi % max(1, count($components) - 1), 3) ?: $components;
            foreach (array_values($picked) as $li => $c) {
                $qty = $ctx->pick([1, 2, 4]);
                $ctx->insert('mrp_bom_lines', [
                    'bom_id' => $bomId,
                    'product_id' => $c->id,
                    'qty' => $qty,
                    'unit_id' => $c->unit_id,
                    'scrap_pct' => 0,
                    'is_optional' => 0,
                    'sort_order' => $li,
                ]);
                $lines[] = ['product' => $c, 'qty' => $qty];
                $lineCount++;
            }
            $ops = [];
            foreach (self::OPERATIONS as $oi => [$opName, $centerIdx, $setup, $run, $qc]) {
                if ($bi === 1 && $oi === 3) {
                    continue; // second BOM has a shorter routing
                }
                $ops[] = [
                    'id' => $ctx->insert('mrp_bom_operations', [
                        'bom_id' => $bomId,
                        'sequence' => $oi + 1,
                        'name' => $opName,
                        'work_center_id' => $centerIds[$centerIdx],
                        'setup_minutes' => $setup,
                        'run_minutes_per_unit' => $run,
                        'requires_qc' => $qc ? 1 : 0,
                        'instructions' => 'Follow the standard work sheet.',
                    ]),
                    'name' => $opName, 'center' => $centerIds[$centerIdx], 'setup' => $setup, 'run' => $run, 'qc' => $qc,
                    'rate' => self::WORK_CENTERS[$centerIdx][2], 'overhead' => self::WORK_CENTERS[$centerIdx][3],
                ];
                $opCount++;
            }
            $boms[] = ['id' => $bomId, 'fg' => $fg, 'lines' => $lines, 'ops' => $ops];
        }

        // ---- Production orders across the lifecycle
        $plans = ['completed', 'completed', 'in_progress', 'released', 'planned', 'draft'];
        $orderCount = $materialCount = $workOrderCount = $qcCount = $qcLineCount = 0;
        foreach ($plans as $pi => $status) {
            $bom = $boms[$pi % count($boms)];
            $qty = $ctx->pick([10, 20, 25, 50]);
            $start = $status === 'completed' ? $ctx->randomDate(60)
                : ($status === 'in_progress' ? now()->subDays(random_int(1, 3)) : now()->addDays(random_int(2, 20)));
            $end = $start->copy()->addDays(random_int(2, 6));
            $done = $status === 'completed';
            $started = in_array($status, ['completed', 'in_progress'], true);
            $produced = $done ? $qty - ($pi === 1 ? 1 : 0) : ($status === 'in_progress' ? (int) floor($qty / 2) : 0);

            // Costs from the BOM (materials) and routing (labour + overhead).
            $material = 0.0;
            foreach ($bom['lines'] as $l) {
                $material += (float) $l['product']->cost * $l['qty'] * $qty;
            }
            $labour = $overhead = 0.0;
            foreach ($bom['ops'] as $op) {
                $hours = ($op['setup'] + $op['run'] * $qty) / 60;
                $labour += $hours * $op['rate'];
                $overhead += $hours * $op['overhead'];
            }
            $total = round($material + $labour + $overhead, 2);

            $orderId = $ctx->insert('mrp_production_orders', [
                'reference' => MrpProductionOrder::nextReference('MO'),
                'bom_id' => $bom['id'],
                'product_id' => $bom['fg']->id,
                'qty_planned' => $qty,
                'qty_produced' => $produced,
                'qty_scrapped' => $pi === 1 ? 1 : 0,
                'warehouse_id' => $warehouseId,
                'fg_warehouse_id' => $warehouseId,
                'status' => $status,
                'priority' => $ctx->pick(['low', 'normal', 'normal', 'high', 'urgent']),
                'planned_start' => $start->toDateString(),
                'planned_end' => $end->toDateString(),
                'actual_start' => $started ? $start : null,
                'actual_end' => $done ? $end : null,
                'material_cost' => $done ? round($material, 2) : 0,
                'labour_cost' => $done ? round($labour, 2) : 0,
                'overhead_cost' => $done ? round($overhead, 2) : 0,
                'total_cost' => $done ? $total : 0,
                'unit_cost' => $done && $produced > 0 ? round($total / $produced, 4) : 0,
                'planned_cost' => $total,
                'materials_issued' => $started ? 1 : 0,
                'qc_required' => 1,
                'qc_passed' => $done ? 1 : 0,
                'notes' => 'Demo data',
                'created_by' => $ctx->userId,
                'created_at' => $start->copy()->subDays(2), 'updated_at' => $start,
            ]);
            $orderCount++;

            foreach ($bom['lines'] as $l) {
                $required = $l['qty'] * $qty;
                $unitCost = (float) $l['product']->cost;
                $ctx->insert('mrp_production_order_materials', [
                    'production_order_id' => $orderId,
                    'product_id' => $l['product']->id,
                    'qty_required' => $required,
                    'qty_issued' => $started ? $required : 0,
                    'qty_returned' => 0,
                    'unit_id' => $l['product']->unit_id,
                    'unit_cost' => $unitCost,
                    'total_cost' => round($unitCost * $required, 2),
                    'is_optional' => 0,
                ]);
                $materialCount++;
            }

            $lastWorkOrderId = null;
            if ($status !== 'draft') {
                foreach ($bom['ops'] as $oi => $op) {
                    $woStatus = $done ? 'completed'
                        : ($status === 'in_progress' ? ($oi === 0 ? 'completed' : ($oi === 1 ? 'in_progress' : 'pending')) : 'pending');
                    $planned = round($op['setup'] + $op['run'] * $qty, 2);
                    $actual = $woStatus === 'completed' ? round($planned * random_int(90, 115) / 100, 2)
                        : ($woStatus === 'in_progress' ? round($planned / 2, 2) : 0);
                    $woStart = $start->copy()->addHours($oi * 4);
                    $lastWorkOrderId = $ctx->insert('mrp_work_orders', [
                        'production_order_id' => $orderId,
                        'bom_operation_id' => $op['id'],
                        'sequence' => $oi + 1,
                        'name' => $op['name'],
                        'work_center_id' => $op['center'],
                        'employee_id' => $employeeIds ? $ctx->pick($employeeIds) : null,
                        'status' => $woStatus,
                        'planned_minutes' => $planned,
                        'actual_minutes' => $actual,
                        'qty_completed' => $woStatus === 'completed' ? $produced : 0,
                        'qty_rejected' => 0,
                        'started_at' => $woStatus !== 'pending' ? $woStart : null,
                        'finished_at' => $woStatus === 'completed' ? $woStart->copy()->addMinutes((int) $actual) : null,
                        'labour_cost' => round($actual / 60 * $op['rate'], 2),
                        'overhead_cost' => round($actual / 60 * $op['overhead'], 2),
                        'requires_qc' => $op['qc'] ? 1 : 0,
                        'notes' => null,
                    ]);
                    $workOrderCount++;
                }
            }

            // Quality: a final check on completed orders, in-process on the running one.
            if ($done || $status === 'in_progress') {
                $inspected = $done ? $qty : (int) floor($qty / 2);
                $rejected = $pi === 1 ? 1 : 0;
                $checkId = $ctx->insert('mrp_quality_checks', [
                    'reference' => MrpQualityCheck::nextReference('QC'),
                    'production_order_id' => $orderId,
                    'work_order_id' => $done ? $lastWorkOrderId : null,
                    'type' => $done ? 'final' : 'in_process',
                    'status' => $rejected > 0 ? 'partial' : 'passed',
                    'qty_inspected' => $inspected,
                    'qty_passed' => $inspected - $rejected,
                    'qty_rejected' => $rejected,
                    'inspector_id' => $ctx->userId,
                    'checked_at' => $done ? $end : now(),
                    'notes' => $rejected ? 'One unit scrapped for a surface defect.' : 'All units within spec.',
                ]);
                $qcCount++;
                foreach (self::QC_PARAMETERS as $qi => [$param, $expected]) {
                    $fail = $rejected > 0 && $qi === 1;
                    $ctx->insert('mrp_quality_check_lines', [
                        'quality_check_id' => $checkId,
                        'parameter' => $param,
                        'expected' => $expected,
                        'actual' => $fail ? 'Scratch on 1 unit' : 'OK',
                        'result' => $fail ? 'fail' : 'pass',
                        'sort_order' => $qi,
                    ]);
                    $qcLineCount++;
                }
            }
        }

        return [
            'Work centers' => count($centerIds),
            'BOMs' => count($boms),
            'BOM lines' => $lineCount,
            'BOM operations' => $opCount,
            'Production orders' => $orderCount,
            'Order materials' => $materialCount,
            'Work orders' => $workOrderCount,
            'Quality checks' => $qcCount,
            'Quality check lines' => $qcLineCount,
        ];
    }

    /** Employees to staff work orders: demo ones first, else the company's own (read-only). */
    private function employeeIds(DemoContext $ctx): array
    {
        $ids = $ctx->demoIds('employees');
        if (! $ids) {
            $ids = DB::table('employees')->where('company_id', $ctx->companyId)
                ->whereNull('deleted_at')->limit(10)->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return $ids;
    }

    /** Up to 6 products (id, name, cost, unit_id): demo -> company's own -> created here. */
    private function products(DemoContext $ctx): array
    {
        $cols = ['id', 'name', 'cost', 'unit_id'];
        $ids = $ctx->demoIds('products');
        if ($ids) {
            $rows = DB::table('products')->whereIn('id', $ids)->whereNull('deleted_at')->orderBy('id')->limit(6)->get($cols)->all();
            if (count($rows) >= 3) {
                return $rows;
            }
        }
        $rows = DB::table('products')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
            ->orderBy('id')->limit(6)->get($cols)->all();
        if (count($rows) >= 3) {
            return $rows;
        }

        return $this->makeProducts($ctx);
    }

    private function makeProducts(DemoContext $ctx): array
    {
        $categoryId = $ctx->insert('categories', ['code' => 'DEMOMRP'.random_int(100, 999), 'name' => 'Demo Manufacturing']);
        $unitId = DB::table('units')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->value('id');
        if (! $unitId) {
            $unitId = $ctx->insert('units', [
                'name' => 'Piece', 'ShortName' => 'pc', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1,
            ]);
        }
        $items = [
            ['Wooden Office Desk', 120, 249], ['Oak Bookshelf', 85, 179],
            ['Oak Panel 120x60', 18, 30], ['Steel Screw Pack (100)', 4, 8],
            ['Wood Varnish 1L', 9, 16], ['Drawer Handle', 2.5, 5],
        ];
        $out = [];
        foreach ($items as [$name, $cost, $price]) {
            $id = $ctx->insert('products', [
                'type' => 'is_single',
                'code' => 'DEMO'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                'Type_barcode' => 'CODE128',
                'name' => $name,
                'cost' => $cost, 'price' => $price,
                'wholesale_price' => round($price * 0.9, 2), 'min_price' => round($cost * 1.05, 2),
                'category_id' => $categoryId,
                'unit_id' => $unitId, 'unit_sale_id' => $unitId, 'unit_purchase_id' => $unitId,
                'tax_method' => '1', 'TaxNet' => 0, 'stock_alert' => 5,
                'is_variant' => 0, 'is_imei' => 0, 'is_active' => 1,
                'image' => 'no-image.png', 'note' => 'Demo data',
            ]);
            foreach ($ctx->warehouseIds as $wid) {
                $ctx->insert('product_warehouse', [
                    'product_id' => $id, 'warehouse_id' => $wid, 'qte' => random_int(20, 150), 'manage_stock' => 1,
                ]);
            }
            $out[] = (object) ['id' => $id, 'name' => $name, 'cost' => $cost, 'unit_id' => $unitId];
        }

        return $out;
    }
}
