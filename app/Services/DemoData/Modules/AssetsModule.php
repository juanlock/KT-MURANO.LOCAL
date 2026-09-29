<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;

/**
 * Asset management examples: categories, assets (placed in the company's
 * warehouses -- asset tables have no company_id, the SPA scopes by
 * warehouse), check-out assignments to the company's users, maintenance
 * history/schedule and, when the company has 2+ warehouses, a transfer.
 */
class AssetsModule extends DemoModule
{
    private const CATEGORIES = [
        'IT Equipment' => 'Laptops, monitors and peripherals',
        'Office Furniture' => 'Desks, chairs and storage',
        'Vehicles & Handling' => 'Carts, trolleys and handling gear',
        'Tools & Machinery' => 'Power tools and workshop machines',
    ];
    // [name, category, cost, life months, supplier, status]
    private const ASSETS = [
        ['Dell Latitude 5440 Laptop', 'IT Equipment', 1150, 36, 'Dell Store', 'in_use'],
        ['HP LaserJet Pro Printer', 'IT Equipment', 420, 48, 'Office Depot', 'in_use'],
        ['27" LG Monitor', 'IT Equipment', 260, 48, 'Best Buy', 'in_use'],
        ['Ergonomic Office Chair', 'Office Furniture', 310, 60, 'IKEA Business', 'in_use'],
        ['Standing Desk', 'Office Furniture', 540, 84, 'IKEA Business', 'in_use'],
        ['Hand Pallet Truck', 'Vehicles & Handling', 690, 96, 'Toyota Material Handling', 'maintenance'],
        ['Bosch Cordless Drill Set', 'Tools & Machinery', 230, 36, 'Home Depot', 'in_use'],
    ];

    public function key(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return 'Assets (Categories, Assets, Assignments, Maintenance)';
    }

    public function dataTables(): array
    {
        return ['asset_categories', 'assets', 'asset_assignments', 'asset_maintenances'];
    }

    public function generate(DemoContext $ctx): array
    {
        $userIds = DB::table('users')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
            ->limit(10)->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [$ctx->userId];

        $catByBase = [];
        foreach (self::CATEGORIES as $base => $desc) {
            // Category names are unique install-wide.
            $name = DB::table('asset_categories')->where('name', $base)->exists() ? $base.' '.random_int(100, 999) : $base;
            $catByBase[$base] = $ctx->insert('asset_categories', ['name' => $name, 'description' => $desc]);
        }
        $multiWarehouse = count($ctx->warehouseIds) > 1;

        // Laptop, chair and drill are checked out (first two still out).
        $assignPlan = [0 => 'assigned', 3 => 'assigned', 6 => 'returned'];
        $assets = [];
        foreach (self::ASSETS as $i => [$name, $cat, $cost, $life, $supplier, $status]) {
            $bought = now()->subMonths(random_int(3, 30))->startOfDay();
            $user = isset($assignPlan[$i]) ? $ctx->pick($userIds) : null;
            $assets[$i] = [
                'id' => $ctx->insert('assets', [
                    'tag' => 'AST-'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
                    'name' => $name,
                    'asset_category_id' => $catByBase[$cat],
                    'serial_number' => 'SN'.random_int(10000000, 99999999),
                    'description' => 'Demo data',
                    'purchase_date' => $bought->toDateString(),
                    'purchase_cost' => $cost,
                    'supplier' => $supplier,
                    'warranty_expiry' => $bought->copy()->addMonths(24)->toDateString(),
                    'depreciation_method' => $i % 3 === 2 ? 'declining_balance' : 'straight_line',
                    'useful_life_months' => $life,
                    'salvage_value' => round($cost * 0.1, 2),
                    'status' => $status,
                    // Asset #2 ends up in the second warehouse via the transfer below.
                    'warehouse_id' => $multiWarehouse && $i === 2 ? $ctx->warehouseIds[1] : $ctx->warehouseIds[0],
                    'assigned_to_id' => ($assignPlan[$i] ?? null) === 'assigned' ? $user : null,
                    'last_verification' => now()->subDays(random_int(10, 120))->toDateString(),
                    'next_validation' => now()->addDays(random_int(30, 200))->toDateString(),
                    'created_at' => $bought, 'updated_at' => $bought,
                ]),
                'user' => $user,
                'bought' => $bought,
            ];
        }

        $assignments = 0;
        foreach ($assignPlan as $i => $state) {
            $out = now()->subDays(random_int(10, 60))->startOfDay();
            $returned = $state === 'returned';
            $ctx->insert('asset_assignments', [
                'asset_id' => $assets[$i]['id'],
                'user_id' => $assets[$i]['user'],
                'assigned_on' => $out->toDateString(),
                'due_back_on' => $out->copy()->addDays($i === 3 ? 180 : 30)->toDateString(),
                'returned_on' => $returned ? $out->copy()->addDays(7)->toDateString() : null,
                'condition_out' => 'Good',
                'condition_in' => $returned ? 'Good' : null,
                'notes' => 'Demo data',
                'status' => $state,
                'created_by' => $ctx->userId,
            ]);
            $assignments++;
        }

        // [asset index, type, title, vendor, cost, status, days offset]
        $maint = [
            [1, 'service', 'Toner replacement and cleaning', 'Office Depot', 85, 'completed', -40],
            [5, 'repair', 'Hydraulic pump seal replacement', 'Toyota Material Handling', 145, 'in_progress', -2],
            [0, 'upgrade', 'RAM upgrade to 32 GB', 'Dell Store', 120, 'completed', -75],
            [6, 'inspection', 'Annual battery and safety check', 'Home Depot', 0, 'scheduled', 14],
            [4, 'service', 'Motor lubrication', null, 40, 'scheduled', 30],
        ];
        foreach ($maint as [$i, $type, $title, $vendor, $cost, $status, $offset]) {
            $date = now()->addDays($offset)->startOfDay();
            $ctx->insert('asset_maintenances', [
                'asset_id' => $assets[$i]['id'],
                'type' => $type,
                'title' => $title,
                'vendor' => $vendor,
                'scheduled_date' => $date->toDateString(),
                'completed_date' => $status === 'completed' ? $date->toDateString() : null,
                'cost' => $cost,
                'next_due_date' => $status === 'completed' ? $date->copy()->addMonths(6)->toDateString() : null,
                'status' => $status,
                'notes' => 'Demo data',
                'created_by' => $ctx->userId,
            ]);
        }

        $transfers = 0;
        if ($multiWarehouse) {
            $ctx->insert('asset_transfers', [
                'asset_id' => $assets[2]['id'],
                'from_warehouse_id' => $ctx->warehouseIds[0],
                'to_warehouse_id' => $ctx->warehouseIds[1],
                'transfer_date' => now()->subDays(20)->toDateString(),
                'reason' => 'Relocated to branch office',
                'notes' => 'Demo data',
                'created_by' => $ctx->userId,
            ]);
            $transfers = 1;
        }

        $out = [
            'Asset categories' => count($catByBase),
            'Assets' => count($assets),
            'Assignments' => $assignments,
            'Maintenance records' => count($maint),
        ];
        if ($transfers) {
            $out['Transfers'] = $transfers;
        }

        return $out;
    }
}
