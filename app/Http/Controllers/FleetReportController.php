<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleFuelLog;
use App\Models\VehicleMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fleet reports, all gated behind `fleet_reports` (the VehiclePolicy@report
 * ability) rather than the individual log permissions — a manager reading cost
 * summaries doesn't need write access to the fuel log.
 *
 * Every report answers { rows, totalRows, totals } so ReportPage's
 * export-everything refetch (limit=-1) works without special cases. Rows are
 * built per vehicle in PHP: the numbers combine three tables plus a derived
 * efficiency figure, which no single GROUP BY produces honestly.
 */
class FleetReportController extends Controller
{
    use \App\Traits\ScopesWarehouseAccess;

    /** Running cost per vehicle: fuel + maintenance, distance and cost/km. */
    public function costs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Vehicle::class);

        $vehicles = $this->vehicleQuery($request)->get();
        $fuel = $this->fuelTotals($request);
        $maintenance = $this->maintenanceTotals($request);

        $rows = $vehicles->map(function ($v) use ($fuel, $maintenance) {
            $fuelCost = (float) ($fuel[$v->id]->cost ?? 0);
            $maintCost = (float) ($maintenance[$v->id]->cost ?? 0);
            $distance = Vehicle::distanceCovered($v->id);
            $total = $fuelCost + $maintCost;

            return [
                'id' => $v->id,
                'vehicle_name' => $v->name,
                'plate_number' => $v->plate_number,
                'type' => $v->type,
                'status' => $v->status,
                'warehouse_name' => $v->warehouse ? $v->warehouse->name : null,
                'fuel_cost' => round($fuelCost, 2),
                'fuel_quantity' => round((float) ($fuel[$v->id]->quantity ?? 0), 2),
                'maintenance_cost' => round($maintCost, 2),
                'maintenance_count' => (int) ($maintenance[$v->id]->entries ?? 0),
                'total_cost' => round($total, 2),
                'distance' => round($distance, 2),
                'cost_per_distance' => $distance > 0 ? round($total / $distance, 2) : null,
                'efficiency' => Vehicle::fuelEfficiency($v->id),
            ];
        });

        return $this->paginated($request, $rows, [
            'fuel_cost' => round($rows->sum('fuel_cost'), 2),
            'maintenance_cost' => round($rows->sum('maintenance_cost'), 2),
            'total_cost' => round($rows->sum('total_cost'), 2),
            'distance' => round($rows->sum('distance'), 2),
        ], 'total_cost');
    }

    /** Fuel efficiency and spend per vehicle. */
    public function fuel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Vehicle::class);

        $vehicles = $this->vehicleQuery($request)->get();
        $fuel = $this->fuelTotals($request);

        $rows = $vehicles->map(function ($v) use ($fuel) {
            $cost = (float) ($fuel[$v->id]->cost ?? 0);
            $quantity = (float) ($fuel[$v->id]->quantity ?? 0);
            $distance = Vehicle::distanceCovered($v->id);

            return [
                'id' => $v->id,
                'vehicle_name' => $v->name,
                'plate_number' => $v->plate_number,
                'fuel_type' => $v->fuel_type,
                'fill_ups' => (int) ($fuel[$v->id]->entries ?? 0),
                'quantity' => round($quantity, 2),
                'fuel_cost' => round($cost, 2),
                'avg_unit_price' => $quantity > 0 ? round($cost / $quantity, 2) : null,
                'distance' => round($distance, 2),
                'efficiency' => Vehicle::fuelEfficiency($v->id),
                'cost_per_distance' => $distance > 0 ? round($cost / $distance, 2) : null,
            ];
        });

        return $this->paginated($request, $rows, [
            'quantity' => round($rows->sum('quantity'), 2),
            'fuel_cost' => round($rows->sum('fuel_cost'), 2),
            'distance' => round($rows->sum('distance'), 2),
        ], 'fuel_cost');
    }

    /** Maintenance spend per vehicle, split by work type. */
    public function maintenance(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Vehicle::class);

        $vehicles = $this->vehicleQuery($request)->get();

        $byType = VehicleMaintenance::whereNull('deleted_at')
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('service_date', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('service_date', '<=', $request->end_date))
            ->select('vehicle_id', 'type', DB::raw('SUM(cost) as cost'), DB::raw('COUNT(*) as entries'))
            ->groupBy('vehicle_id', 'type')
            ->get()
            ->groupBy('vehicle_id');

        $rows = $vehicles->map(function ($v) use ($byType) {
            $entries = $byType->get($v->id, collect());
            $costFor = fn ($type) => round((float) $entries->firstWhere('type', $type)?->cost, 2);

            return [
                'id' => $v->id,
                'vehicle_name' => $v->name,
                'plate_number' => $v->plate_number,
                'service_cost' => $costFor('service'),
                'repair_cost' => $costFor('repair'),
                'tyres_cost' => $costFor('tyres'),
                'other_cost' => round((float) $entries->whereNotIn('type', ['service', 'repair', 'tyres'])->sum('cost'), 2),
                'entries' => (int) $entries->sum('entries'),
                'total_cost' => round((float) $entries->sum('cost'), 2),
            ];
        });

        return $this->paginated($request, $rows, [
            'service_cost' => round($rows->sum('service_cost'), 2),
            'repair_cost' => round($rows->sum('repair_cost'), 2),
            'tyres_cost' => round($rows->sum('tyres_cost'), 2),
            'other_cost' => round($rows->sum('other_cost'), 2),
            'total_cost' => round($rows->sum('total_cost'), 2),
        ], 'total_cost');
    }

    /** Trips, distance and fuel spend per driver. */
    public function drivers(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Vehicle::class);

        // This report groups by driver rather than by vehicle, so it never goes
        // through vehicleQuery(): restrict it to the vehicles the caller can see.
        $visibleVehicleIds = $this->scopeToWarehouses(
            Vehicle::whereNull('deleted_at'), 'warehouse_id', true
        )->pluck('id')->all();

        $trips = VehicleAssignment::whereNull('deleted_at')
            ->whereIn('vehicle_id', $visibleVehicleIds)
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('start_date', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('start_date', '<=', $request->end_date))
            ->get()
            ->groupBy('employee_id');

        $fuel = VehicleFuelLog::whereNull('deleted_at')
            ->whereIn('vehicle_id', $visibleVehicleIds)
            ->whereNotNull('employee_id')
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('log_date', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('log_date', '<=', $request->end_date))
            ->select('employee_id', DB::raw('SUM(total_cost) as cost'), DB::raw('SUM(quantity) as quantity'))
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $ids = $trips->keys()->merge($fuel->keys())->filter()->unique();
        $employees = Employee::whereIn('id', $ids)->get()->keyBy('id');

        $rows = $ids->map(function ($id) use ($trips, $fuel, $employees) {
            $driverTrips = $trips->get($id, collect());
            $employee = $employees->get($id);

            return [
                'id' => (int) $id,
                'driver_name' => $employee ? trim($employee->firstname . ' ' . $employee->lastname) : '—',
                'trips' => $driverTrips->count(),
                'open_trips' => $driverTrips->where('status', 'active')->count(),
                'distance' => round((float) $driverTrips->sum(fn ($t) => $t->distance ?? 0), 2),
                'fuel_quantity' => round((float) ($fuel[$id]->quantity ?? 0), 2),
                'fuel_cost' => round((float) ($fuel[$id]->cost ?? 0), 2),
            ];
        })->values();

        return $this->paginated($request, $rows, [
            'trips' => $rows->sum('trips'),
            'distance' => round($rows->sum('distance'), 2),
            'fuel_cost' => round($rows->sum('fuel_cost'), 2),
        ], 'distance');
    }

    // ------------------------------------------------------------------
    // Shared pieces
    // ------------------------------------------------------------------

    private function vehicleQuery(Request $request)
    {
        $query = Vehicle::with('warehouse')->whereNull('deleted_at');

        // Every fleet report funnels through here, so the warehouse scope only
        // has to be applied once.
        $this->scopeToWarehouses($query, 'warehouse_id', true);

        return $query
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('id', $request->vehicle_id))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('plate_number', 'LIKE', "%{$search}%");
                });
            });
    }

    private function fuelTotals(Request $request)
    {
        return VehicleFuelLog::whereNull('deleted_at')
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('log_date', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('log_date', '<=', $request->end_date))
            ->select('vehicle_id', DB::raw('SUM(total_cost) as cost'), DB::raw('SUM(quantity) as quantity'), DB::raw('COUNT(*) as entries'))
            ->groupBy('vehicle_id')
            ->get()
            ->keyBy('vehicle_id');
    }

    private function maintenanceTotals(Request $request)
    {
        return VehicleMaintenance::whereNull('deleted_at')
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('service_date', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('service_date', '<=', $request->end_date))
            ->select('vehicle_id', DB::raw('SUM(cost) as cost'), DB::raw('COUNT(*) as entries'))
            ->groupBy('vehicle_id')
            ->get()
            ->keyBy('vehicle_id');
    }

    /**
     * Sort + page an assembled collection. limit=-1 returns everything, which
     * is what ReportPage's export uses.
     */
    private function paginated(Request $request, $rows, array $totals, $defaultSort)
    {
        $sortField = $request->SortField ?: $defaultSort;
        $descending = strtolower($request->SortType ?: 'desc') !== 'asc';

        if ($rows->count() && array_key_exists($sortField, $rows->first())) {
            $rows = $descending
                ? $rows->sortByDesc($sortField, SORT_NATURAL | SORT_FLAG_CASE)
                : $rows->sortBy($sortField, SORT_NATURAL | SORT_FLAG_CASE);
        }
        $rows = $rows->values();

        $totalRows = $rows->count();
        $perPage = (int) ($request->limit ?? 10);
        if ($perPage === -1) {
            return response()->json(['rows' => $rows, 'totalRows' => $totalRows, 'totals' => $totals]);
        }

        $page = max(1, (int) $request->get('page', 1));

        return response()->json([
            'rows' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'totalRows' => $totalRows,
            'totals' => $totals,
        ]);
    }
}
