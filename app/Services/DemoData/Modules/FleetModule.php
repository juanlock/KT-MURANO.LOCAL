<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;

/**
 * Fleet examples: company vehicles (company_id + one of its warehouses),
 * driver assignments/trips, fuel logs and maintenance history.
 *
 * Drivers are employees: demo employees when another module created them,
 * else the company's own employees (referenced read-only). With no employee
 * at all, vehicles get no default driver and assignments are skipped
 * (vehicle_assignments.employee_id is required).
 */
class FleetModule extends DemoModule
{
    // [name, make, model, year, type, fuel, tank, color, start odometer]
    private const VEHICLES = [
        ['Delivery Van 1', 'Ford', 'Transit Custom', 2022, 'van', 'diesel', 70, 'White', 48200],
        ['Delivery Van 2', 'Mercedes-Benz', 'Sprinter', 2021, 'van', 'diesel', 75, 'Silver', 71350],
        ['Sales Car', 'Toyota', 'Corolla Hybrid', 2023, 'car', 'hybrid', 43, 'Blue', 21400],
        ['Box Truck', 'Isuzu', 'NPR HD', 2019, 'truck', 'diesel', 150, 'White', 132900],
        ['Warehouse Forklift', 'Toyota', '8FGCU25', 2020, 'forklift', 'lpg', 20, 'Orange', 6400],
    ];
    private const STATIONS = ['Shell Main St', 'BP Highway 9', 'Chevron Downtown', 'Exxon Industrial Park'];

    public function key(): string
    {
        return 'fleet';
    }

    public function label(): string
    {
        return 'Fleet (Vehicles, Driver assignments, Fuel logs, Maintenance)';
    }

    public function dataTables(): array
    {
        // Only vehicles carry company_id; trips, fuel logs and maintenance
        // hang off a vehicle, so "has a real fleet" = has real vehicles.
        return ['vehicles'];
    }

    public function requiredTables(): array
    {
        return ['vehicles', 'vehicle_assignments', 'vehicle_fuel_logs', 'vehicle_maintenances'];
    }

    public function generate(DemoContext $ctx): array
    {
        $employeeIds = $ctx->demoIds('employees');
        if (! $employeeIds) {
            $employeeIds = DB::table('employees')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
                ->limit(10)->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        $vehicles = [];
        foreach (self::VEHICLES as $i => [$name, $make, $model, $year, $type, $fuel, $tank, $color, $odo]) {
            $bought = now()->subMonths(random_int(6, 48))->startOfDay();
            $vehicles[$i] = [
                'id' => $ctx->insert('vehicles', [
                    'name' => $name,
                    'plate_number' => strtoupper(chr(random_int(65, 90)).chr(random_int(65, 90)).chr(random_int(65, 90))).'-'.random_int(1000, 9999),
                    'vin' => strtoupper(substr(md5(uniqid('', true)), 0, 17)),
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                    'color' => $color,
                    'type' => $type,
                    'status' => $i === 3 ? 'maintenance' : 'active',
                    'warehouse_id' => $ctx->pick($ctx->warehouseIds),
                    'employee_id' => $employeeIds && $type !== 'forklift' ? $employeeIds[$i % count($employeeIds)] : null,
                    'fuel_type' => $fuel,
                    'tank_capacity' => $tank,
                    'odometer' => $odo + 1500,
                    'purchase_date' => $bought->toDateString(),
                    'purchase_price' => $ctx->pick([18500, 24900, 32000, 41500]),
                    'insurance_provider' => $ctx->pick(['Allianz', 'AXA', 'State Farm']),
                    'insurance_policy' => 'POL-'.random_int(100000, 999999),
                    'insurance_expiry' => now()->addDays(random_int(20, 330))->toDateString(),
                    'registration_expiry' => now()->addDays(random_int(40, 360))->toDateString(),
                    'inspection_expiry' => now()->addDays(random_int(10, 200))->toDateString(),
                    'image' => null,
                    'notes' => 'Demo data',
                    'created_at' => $bought, 'updated_at' => $bought,
                ]),
                'odo' => $odo,
                'fuel' => $fuel,
                'tank' => $tank,
                'type' => $type,
            ];
        }

        // Trips / driver assignments (road vehicles only).
        $assignments = 0;
        if ($employeeIds) {
            $trips = [
                [0, 'completed', 'Weekly store deliveries', 'North district', -12, 180],
                [1, 'completed', 'Supplier pickup', 'Industrial Zone', -6, 95],
                [2, 'active', 'Client visits', 'City centre', -1, null],
                [0, 'active', 'Daily delivery round', 'South district', 0, null],
            ];
            foreach ($trips as [$v, $status, $purpose, $dest, $days, $km]) {
                $start = now()->addDays($days)->setTime(8, 0);
                $startOdo = $vehicles[$v]['odo'] + random_int(100, 900);
                $ctx->insert('vehicle_assignments', [
                    'vehicle_id' => $vehicles[$v]['id'],
                    'employee_id' => $ctx->pick($employeeIds),
                    'start_date' => $start,
                    'end_date' => $status === 'completed' ? $start->copy()->addHours(9) : null,
                    'start_odometer' => $startOdo,
                    'end_odometer' => $status === 'completed' ? $startOdo + $km : null,
                    'purpose' => $purpose,
                    'destination' => $dest,
                    'status' => $status,
                    'notes' => 'Demo data',
                    'created_by' => $ctx->userId,
                ]);
                $assignments++;
            }
        }

        // Fuel logs: a few fills per vehicle over the last 2 months.
        $prices = ['diesel' => 1.62, 'petrol' => 1.55, 'hybrid' => 1.55, 'lpg' => 0.85, 'electric' => 0.30, 'cng' => 1.10];
        $fuelLogs = 0;
        foreach ([0, 1, 2, 3, 0, 1, 4, 2] as $n => $v) {
            $veh = $vehicles[$v];
            $qty = round($veh['tank'] * random_int(55, 95) / 100, 2);
            $price = $prices[$veh['fuel']] ?? 1.5;
            $ctx->insert('vehicle_fuel_logs', [
                'vehicle_id' => $veh['id'],
                'employee_id' => $employeeIds ? $ctx->pick($employeeIds) : null,
                'log_date' => now()->subDays(60 - $n * 7)->toDateString(),
                'odometer' => $veh['odo'] + ($veh['type'] === 'forklift' ? 20 : 150) * ($n + 1),
                'quantity' => $qty,
                'unit_price' => $price,
                'total_cost' => round($qty * $price, 2),
                'station' => $veh['type'] === 'forklift' ? 'On-site LPG cage' : $ctx->pick(self::STATIONS),
                'full_tank' => random_int(0, 3) ? 1 : 0,
                'notes' => null,
                'created_by' => $ctx->userId,
            ]);
            $fuelLogs++;
        }

        // [vehicle, type, title, vendor, cost, status, days offset]
        $maint = [
            [0, 'service', 'Oil & filter change', 'QuickLube Center', 145, 'completed', -45],
            [1, 'tyres', 'Replace front tyres', 'TyrePlus', 380, 'completed', -20],
            [3, 'repair', 'Brake pads and rotors', 'Isuzu Service', 620, 'in_progress', -1],
            [2, 'inspection', 'Annual roadworthiness inspection', 'City Test Station', 60, 'scheduled', 18],
            [4, 'service', 'Mast chain lubrication & check', 'Toyota Material Handling', 95, 'scheduled', 25],
        ];
        foreach ($maint as [$v, $type, $title, $vendor, $cost, $status, $days]) {
            $date = now()->addDays($days)->startOfDay();
            $ctx->insert('vehicle_maintenances', [
                'vehicle_id' => $vehicles[$v]['id'],
                'type' => $type,
                'title' => $title,
                'service_date' => $date->toDateString(),
                'odometer' => $status === 'scheduled' ? null : $vehicles[$v]['odo'] + random_int(200, 1200),
                'cost' => $cost,
                'vendor' => $vendor,
                'status' => $status,
                'next_service_date' => $status === 'completed' ? $date->copy()->addMonths(6)->toDateString() : null,
                'next_service_odometer' => $status === 'completed' ? $vehicles[$v]['odo'] + 10000 : null,
                'notes' => 'Demo data',
                'created_by' => $ctx->userId,
            ]);
        }

        return [
            'Vehicles' => count($vehicles),
            'Driver assignments' => $assignments,
            'Fuel logs' => $fuelLogs,
            'Maintenance records' => count($maint),
        ];
    }
}
