<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;

/**
 * Asset management examples that fit the company's business type (Demo Data
 * -> "What kind of business", companies.business_type): a landscaper gets
 * mowers, trucks and trailers; a salon, styling chairs and dryers; an IT
 * consultant, laptops, servers and network gear; an office, copiers and
 * furniture... Categories (per company), assets placed in the company's
 * warehouses (asset tables have no company_id: the location is the company
 * boundary), check-out assignments to the company's users, maintenance
 * history / schedule and, with 2+ warehouses, a transfer.
 */
class AssetsModule extends DemoModule
{
    /**
     * type => [categories [name => description], assets [name, category, cost, life months, supplier, status],
     *          maintenance [asset index, type, title, vendor, cost, status, days offset]].
     * Assets 0 and 3 end up checked out, 6 was checked out and returned.
     */
    private const CATALOG = [
        'general' => [
            'categories' => [
                'IT Equipment' => 'Laptops, monitors and peripherals',
                'Office Furniture' => 'Desks, chairs and storage',
                'Vehicles & Handling' => 'Carts, trolleys and handling gear',
                'Tools & Machinery' => 'Power tools and workshop machines',
            ],
            'assets' => [
                ['Dell Latitude 5440 Laptop', 'IT Equipment', 1150, 36, 'Dell Store', 'in_use'],
                ['HP LaserJet Pro Printer', 'IT Equipment', 420, 48, 'Office Depot', 'in_use'],
                ['27" LG Monitor', 'IT Equipment', 260, 48, 'Best Buy', 'in_use'],
                ['Ergonomic Office Chair', 'Office Furniture', 310, 60, 'IKEA Business', 'in_use'],
                ['Standing Desk', 'Office Furniture', 540, 84, 'IKEA Business', 'in_use'],
                ['Hand Pallet Truck', 'Vehicles & Handling', 690, 96, 'Toyota Material Handling', 'maintenance'],
                ['Bosch Cordless Drill Set', 'Tools & Machinery', 230, 36, 'Home Depot', 'in_use'],
            ],
            'maintenance' => [
                [1, 'service', 'Toner replacement and cleaning', 'Office Depot', 85, 'completed', -40],
                [5, 'repair', 'Hydraulic pump seal replacement', 'Toyota Material Handling', 145, 'in_progress', -2],
                [0, 'upgrade', 'RAM upgrade to 32 GB', 'Dell Store', 120, 'completed', -75],
                [6, 'inspection', 'Annual battery and safety check', 'Home Depot', 0, 'scheduled', 14],
                [4, 'service', 'Motor lubrication', null, 40, 'scheduled', 30],
            ],
        ],
        'landscaping' => [
            'categories' => [
                'Mowers & Power Equipment' => 'Mowers, blowers, trimmers and aerators',
                'Vehicles & Trailers' => 'Trucks and equipment trailers',
                'Hand Tools' => 'Hedge trimmers, shovels, rakes and small tools',
                'Office & IT' => 'Tablets and office equipment',
            ],
            'assets' => [
                ['Exmark Lazer Z Zero-Turn Mower', 'Mowers & Power Equipment', 11800, 84, 'Exmark Dealer', 'in_use'],
                ['Stihl BR 800 Backpack Blower', 'Mowers & Power Equipment', 650, 48, 'Stihl Dealer', 'in_use'],
                ['Ford F-250 Pickup Truck', 'Vehicles & Trailers', 46000, 96, 'Ford Dealer', 'in_use'],
                ['16 ft Utility Trailer', 'Vehicles & Trailers', 3200, 120, 'Big Tex Trailers', 'in_use'],
                ['Hedge Trimmer & Hand Tools Kit', 'Hand Tools', 520, 36, 'Home Depot', 'in_use'],
                ['Ryan Lawnaire Core Aerator', 'Mowers & Power Equipment', 3900, 84, 'Ryan Equipment', 'maintenance'],
                ['Crew Tablet iPad 10th Gen', 'Office & IT', 450, 36, 'Apple Store', 'in_use'],
            ],
            'maintenance' => [
                [1, 'service', 'Air filter and spark plug change', 'Stihl Dealer', 45, 'completed', -40],
                [5, 'repair', 'Tine shaft and drive belt replacement', 'Ryan Equipment', 260, 'in_progress', -2],
                [0, 'service', 'Blade sharpening and oil change', 'Exmark Dealer', 120, 'completed', -75],
                [6, 'inspection', 'Screen and battery check', null, 0, 'scheduled', 14],
                [2, 'service', 'Oil change and tire rotation', 'Ford Dealer', 95, 'scheduled', 30],
            ],
        ],
        'salon' => [
            'categories' => [
                'Salon Chairs & Stations' => 'Styling chairs, stations and backwash units',
                'Hair Tools' => 'Dryers, clippers, irons',
                'Spa & Nail Equipment' => 'Pedicure chairs and nail stations',
                'Front Desk & IT' => 'POS tablets and front desk equipment',
            ],
            'assets' => [
                ['iPad POS Terminal', 'Front Desk & IT', 650, 36, 'Apple Store', 'in_use'],
                ['Hydraulic Styling Chair', 'Salon Chairs & Stations', 480, 84, 'Salon Supply Co', 'in_use'],
                ['Shampoo Backwash Unit', 'Salon Chairs & Stations', 1250, 96, 'Salon Supply Co', 'in_use'],
                ['Dyson Supersonic Hair Dryer', 'Hair Tools', 430, 36, 'Sally Beauty', 'in_use'],
                ['Pedicure Spa Chair', 'Spa & Nail Equipment', 2900, 96, 'Spa Equipment Inc', 'in_use'],
                ['Hooded Hair Dryer Station', 'Hair Tools', 380, 60, 'Sally Beauty', 'maintenance'],
                ['Wahl Professional Clipper Set', 'Hair Tools', 210, 24, 'Sally Beauty', 'in_use'],
            ],
            'maintenance' => [
                [1, 'service', 'Hydraulic pump check', 'Salon Supply Co', 60, 'completed', -40],
                [5, 'repair', 'Heating element replacement', 'Sally Beauty', 85, 'in_progress', -2],
                [4, 'service', 'Jet and pipe sanitizing service', 'Spa Equipment Inc', 120, 'completed', -75],
                [6, 'inspection', 'Blade alignment and oiling', null, 0, 'scheduled', 14],
                [2, 'service', 'Drain and faucet maintenance', null, 40, 'scheduled', 30],
            ],
        ],
        'it_services' => [
            'categories' => [
                'Computers & Laptops' => 'Staff and loaner laptops',
                'Network Equipment' => 'Firewalls, switches and test tools',
                'Servers & Lab' => 'Servers, storage and lab gear',
                'Office Furniture' => 'Desks and chairs',
            ],
            'assets' => [
                ['MacBook Pro 14" M3', 'Computers & Laptops', 2400, 36, 'Apple Store', 'in_use'],
                ['Cisco Meraki MX68 Firewall', 'Network Equipment', 900, 60, 'CDW', 'in_use'],
                ['Dell PowerEdge R650 Server', 'Servers & Lab', 6800, 60, 'Dell Technologies', 'in_use'],
                ['Lenovo ThinkPad T14 (loaner)', 'Computers & Laptops', 1350, 36, 'CDW', 'in_use'],
                ['Standing Desk', 'Office Furniture', 540, 84, 'IKEA Business', 'in_use'],
                ['Synology NAS DS1821+', 'Servers & Lab', 1100, 60, 'Amazon Business', 'maintenance'],
                ['Fluke Network Cable Tester', 'Network Equipment', 780, 60, 'Fluke Networks', 'in_use'],
            ],
            'maintenance' => [
                [1, 'upgrade', 'Firmware update and license renewal', 'CDW', 150, 'completed', -40],
                [5, 'repair', 'Failed drive replacement (RAID rebuild)', 'Amazon Business', 210, 'in_progress', -2],
                [0, 'upgrade', 'Battery health check and OS upgrade', 'Apple Store', 0, 'completed', -75],
                [6, 'inspection', 'Annual calibration', 'Fluke Networks', 95, 'scheduled', 14],
                [2, 'service', 'Firmware and BIOS update', null, 0, 'scheduled', 30],
            ],
        ],
        'professional' => [
            'categories' => [
                'IT Equipment' => 'Laptops and peripherals',
                'Office Furniture' => 'Desks, chairs and storage',
                'Office Machines' => 'Copiers, scanners and shredders',
                'Conference Room' => 'Displays and audio for meetings',
            ],
            'assets' => [
                ['Dell Latitude 5440 Laptop', 'IT Equipment', 1150, 36, 'Dell Store', 'in_use'],
                ['Canon imageRUNNER Copier', 'Office Machines', 4200, 60, 'Canon Business', 'in_use'],
                ['Conference Room 65" Display', 'Conference Room', 1300, 60, 'Best Buy Business', 'in_use'],
                ['Ergonomic Office Chair', 'Office Furniture', 310, 60, 'Steelcase', 'in_use'],
                ['Executive Desk', 'Office Furniture', 890, 120, 'Steelcase', 'in_use'],
                ['Fujitsu ScanSnap Document Scanner', 'Office Machines', 420, 48, 'Staples', 'maintenance'],
                ['Poly Conference Speakerphone', 'Conference Room', 350, 48, 'CDW', 'in_use'],
            ],
            'maintenance' => [
                [1, 'service', 'Toner, drum and cleaning service', 'Canon Business', 180, 'completed', -40],
                [5, 'repair', 'Feed roller replacement', 'Staples', 65, 'in_progress', -2],
                [0, 'upgrade', 'SSD upgrade to 1 TB', 'Dell Store', 140, 'completed', -75],
                [6, 'inspection', 'Firmware update', null, 0, 'scheduled', 14],
                [4, 'service', 'Drawer and lock repair', null, 40, 'scheduled', 30],
            ],
        ],
        'restaurant' => [
            'categories' => [
                'Kitchen Equipment' => 'Ranges, dishwashers, espresso machines',
                'Refrigeration' => 'Coolers and freezers',
                'Dining Room' => 'Tables and seating',
                'POS & IT' => 'POS terminals and tablets',
            ],
            'assets' => [
                ['Toast POS Terminal', 'POS & IT', 900, 36, 'Toast', 'in_use'],
                ['Commercial 6-Burner Range', 'Kitchen Equipment', 3800, 120, 'WebstaurantStore', 'in_use'],
                ['Walk-in Cooler', 'Refrigeration', 9500, 144, 'Kolpak', 'in_use'],
                ['Reach-in Freezer', 'Refrigeration', 2700, 96, 'True Refrigeration', 'in_use'],
                ['Dining Tables Set (10)', 'Dining Room', 2400, 120, 'Restaurant Furniture Plus', 'in_use'],
                ['Commercial Dishwasher', 'Kitchen Equipment', 5200, 96, 'Hobart', 'maintenance'],
                ['Espresso Machine', 'Kitchen Equipment', 3100, 72, 'La Marzocco', 'in_use'],
            ],
            'maintenance' => [
                [1, 'service', 'Burner and pilot cleaning', 'WebstaurantStore', 120, 'completed', -40],
                [5, 'repair', 'Wash pump replacement', 'Hobart', 340, 'in_progress', -2],
                [2, 'inspection', 'Compressor and temperature check', 'Kolpak', 150, 'completed', -75],
                [6, 'service', 'Descaling and gasket change', 'La Marzocco', 90, 'scheduled', 14],
                [3, 'service', 'Condenser coil cleaning', null, 60, 'scheduled', 30],
            ],
        ],
        'retail' => [
            'categories' => [
                'Store Fixtures' => 'Showcases and shelving',
                'POS & IT' => 'Registers and label printers',
                'Security' => 'Cameras and alarms',
                'Storage & Handling' => 'Pallet trucks and ladders',
            ],
            'assets' => [
                ['Square POS Register', 'POS & IT', 800, 36, 'Square', 'in_use'],
                ['Zebra Barcode Label Printer', 'POS & IT', 450, 48, 'Zebra', 'in_use'],
                ['Glass Display Showcase', 'Store Fixtures', 1600, 120, 'Store Fixtures Direct', 'in_use'],
                ['Wall Shelving System', 'Store Fixtures', 2200, 120, 'Store Fixtures Direct', 'in_use'],
                ['Security Camera System (8 cameras)', 'Security', 1900, 60, 'ADT', 'in_use'],
                ['Hand Pallet Truck', 'Storage & Handling', 690, 96, 'Uline', 'maintenance'],
                ['Stockroom Ladder', 'Storage & Handling', 260, 96, 'Uline', 'in_use'],
            ],
            'maintenance' => [
                [1, 'service', 'Printhead cleaning and calibration', 'Zebra', 40, 'completed', -40],
                [5, 'repair', 'Hydraulic pump seal replacement', 'Uline', 145, 'in_progress', -2],
                [4, 'inspection', 'Camera and recorder check', 'ADT', 0, 'completed', -75],
                [6, 'inspection', 'Annual safety check', null, 0, 'scheduled', 14],
                [0, 'upgrade', 'Card reader firmware update', 'Square', 0, 'scheduled', 30],
            ],
        ],
    ];

    /** Types sharing another type's set. */
    private const SAME_AS = ['barber' => 'salon'];

    public function key(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return 'Assets (equipment for the business type: categories, assets, assignments, maintenance)';
    }

    /** Company-scoped (asset_categories has company_id; assets don't). */
    public function dataTables(): array
    {
        return ['asset_categories'];
    }

    public function requiredTables(): array
    {
        return ['asset_categories', 'assets', 'asset_assignments', 'asset_maintenances'];
    }

    public function generate(DemoContext $ctx): array
    {
        $type = self::SAME_AS[$ctx->businessType] ?? $ctx->businessType;
        $set = self::CATALOG[$type] ?? self::CATALOG['general'];

        $userIds = DB::table('users')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
            ->whereIn('type', ['admin', 'employee'])->limit(10)->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [$ctx->userId];

        $catByName = [];
        foreach ($set['categories'] as $name => $desc) {
            // Names are unique per company: reuse the company's own category if it has it.
            $existing = DB::table('asset_categories')->where('company_id', $ctx->companyId)->where('name', $name)->whereNull('deleted_at')->value('id');
            $catByName[$name] = $existing ? (int) $existing : $ctx->insert('asset_categories', ['name' => $name, 'description' => $desc]);
        }
        $multiWarehouse = count($ctx->warehouseIds) > 1;

        $assignPlan = [0 => 'assigned', 3 => 'assigned', 6 => 'returned'];
        $assets = [];
        foreach ($set['assets'] as $i => [$name, $cat, $cost, $life, $supplier, $status]) {
            $bought = now()->subMonths(random_int(3, 30))->startOfDay();
            $user = isset($assignPlan[$i]) ? $ctx->pick($userIds) : null;
            $assets[$i] = [
                'id' => $ctx->insert('assets', [
                    'tag' => 'AST-'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
                    'name' => $name,
                    'asset_category_id' => $catByName[$cat],
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
                    // Always this company's own locations (the company boundary for assets).
                    'warehouse_id' => $multiWarehouse && $i === 2 ? $ctx->warehouseIds[1] : $ctx->warehouse(),
                    'assigned_to_id' => ($assignPlan[$i] ?? null) === 'assigned' ? $user : null,
                    'last_verification' => now()->subDays(random_int(10, 120))->toDateString(),
                    'next_validation' => now()->addDays(random_int(30, 200))->toDateString(),
                    'created_at' => $bought, 'updated_at' => $bought,
                ]),
                'user' => $user,
            ];
        }

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
        }

        foreach ($set['maintenance'] as [$i, $mtype, $title, $vendor, $cost, $status, $offset]) {
            $date = now()->addDays($offset)->startOfDay();
            $ctx->insert('asset_maintenances', [
                'asset_id' => $assets[$i]['id'],
                'type' => $mtype,
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

        $out = [
            'Asset categories' => count($catByName),
            'Assets' => count($assets),
            'Assignments' => count($assignPlan),
            'Maintenance records' => count($set['maintenance']),
        ];
        if ($multiWarehouse) {
            $ctx->insert('asset_transfers', [
                'asset_id' => $assets[2]['id'],
                'from_warehouse_id' => $ctx->warehouse(),
                'to_warehouse_id' => $ctx->warehouseIds[1],
                'transfer_date' => now()->subDays(20)->toDateString(),
                'reason' => 'Moved to another location',
                'notes' => 'Demo data',
                'created_by' => $ctx->userId,
            ]);
            $out['Transfers'] = 1;
        }

        return $out;
    }
}
