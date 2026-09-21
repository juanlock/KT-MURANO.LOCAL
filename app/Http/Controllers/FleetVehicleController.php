<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleFuelLog;
use App\Models\VehicleMaintenance;
use App\Models\Warehouse;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vehicle register + the fleet dashboard.
 *
 * Photos go to public/images/vehicles (the convention the rest of the app's
 * uploads follow); the column stores the file name only, and rows carry a ready
 * `image_url`.
 *
 * List endpoints follow the admin's usual contract
 * (page/SortField/SortType/search/limit -> { vehicles, totalRows }) so the Vue
 * `useCrudTable` composable drives them unchanged.
 */
class FleetVehicleController extends Controller
{
    use ScopesWarehouseAccess;

    /** Photo directory, relative to public/. */
    private const IMAGE_DIR = 'images/vehicles';

    /** How far ahead a renewal or service counts as "due soon". */
    private const DUE_SOON_DAYS = 30;

    private const SORTABLE = [
        'id', 'name', 'plate_number', 'make', 'model', 'year', 'type', 'status',
        'odometer', 'purchase_date', 'insurance_expiry', 'registration_expiry', 'created_at',
    ];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Vehicle::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Vehicle::with('warehouse', 'driver')->whereNull('deleted_at');

        // Vehicles are stationed at a warehouse; one with no warehouse belongs
        // to the whole company and stays visible to everyone.
        $this->scopeToWarehouses($query, 'warehouse_id', true);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        // One switch for "anything needing attention in the next 30 days".
        if ($request->boolean('expiring')) {
            $limit = Carbon::today()->addDays(self::DUE_SOON_DAYS)->toDateString();
            $query->where(function ($q) use ($limit) {
                $q->whereDate('insurance_expiry', '<=', $limit)
                    ->orWhereDate('registration_expiry', '<=', $limit)
                    ->orWhereDate('inspection_expiry', '<=', $limit);
            });
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('plate_number', 'LIKE', "%{$search}%")
                    ->orWhere('vin', 'LIKE', "%{$search}%")
                    ->orWhere('make', 'LIKE', "%{$search}%")
                    ->orWhere('model', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $vehicles = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'vehicles' => $vehicles->map(fn ($v) => $this->present($v))->values(),
        ]);
    }

    /** Selects shared by the vehicle form and every fleet log form. */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Vehicle::class);

        return response()->json([
            'vehicles' => Vehicle::whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'name', 'plate_number', 'odometer', 'status'])
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'plate_number' => $v->plate_number,
                    'label' => $v->display_name,
                    'odometer' => (float) $v->odometer,
                    'status' => $v->status,
                ])->values(),
            'warehouses' => Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::orderBy('firstname')->get(['id', 'firstname', 'lastname'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => trim($e->firstname . ' ' . $e->lastname)])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Vehicle::class);

        $request->validate($this->rules(), [], $this->attributes());

        $data = $this->payload($request);
        $data['image'] = $this->storeImage($request);

        Vehicle::create($data);

        return response()->json(['success' => true]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Vehicle::class);

        $vehicle = Vehicle::with('warehouse', 'driver')->whereNull('deleted_at')->findOrFail($id);

        $data = $this->present($vehicle);
        $data['vin'] = $vehicle->vin;
        $data['notes'] = $vehicle->notes;
        $data['insurance_provider'] = $vehicle->insurance_provider;
        $data['insurance_policy'] = $vehicle->insurance_policy;
        $data['tank_capacity'] = $vehicle->tank_capacity === null ? null : (float) $vehicle->tank_capacity;
        $data['stats'] = $this->vehicleStats($vehicle);

        return response()->json(['vehicle' => $data]);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Vehicle::class);

        $vehicle = Vehicle::whereNull('deleted_at')->findOrFail($id);

        return response()->json(['vehicle' => array_merge($vehicle->toArray(), [
            'image_url' => $vehicle->image ? asset(self::IMAGE_DIR . '/' . $vehicle->image) : null,
            'purchase_date' => optional($vehicle->purchase_date)->toDateString(),
            'insurance_expiry' => optional($vehicle->insurance_expiry)->toDateString(),
            'registration_expiry' => optional($vehicle->registration_expiry)->toDateString(),
            'inspection_expiry' => optional($vehicle->inspection_expiry)->toDateString(),
        ])]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Vehicle::class);

        $vehicle = Vehicle::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->rules(), [], $this->attributes());

        $data = $this->payload($request);
        // Only replace the photo when a new one was actually sent.
        $image = $this->storeImage($request);
        if ($image) {
            $this->deleteImage($vehicle->image);
            $data['image'] = $image;
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($vehicle->image);
            $data['image'] = null;
        }

        $vehicle->update($data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Vehicle::class);

        Vehicle::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Vehicle::class);

        $ids = (array) $request->selectedIds;
        Vehicle::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    /**
     * Everything the fleet dashboard shows: counts, running costs, the monthly
     * cost trend and the alert list (renewals + services due or overdue).
     */
    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Vehicle::class);

        $today = Carbon::today();
        $soon = $today->copy()->addDays(self::DUE_SOON_DAYS);
        $monthStart = $today->copy()->startOfMonth();

        // Every tile below is counted over the vehicles this user may see, so
        // the totals and the cost figures describe the same fleet.
        $visibleVehicleIds = $this->scopeToWarehouses(
            Vehicle::whereNull('deleted_at'), 'warehouse_id', true
        )->pluck('id')->all();

        $byStatus = Vehicle::whereNull('deleted_at')->whereIn('id', $visibleVehicleIds)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->toArray();

        $byType = Vehicle::whereNull('deleted_at')->whereIn('id', $visibleVehicleIds)
            ->select('type', DB::raw('count(*) as aggregate'))
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->toArray();

        return response()->json([
            'total' => count($visibleVehicleIds),
            'by_status' => $byStatus,
            'by_type' => collect($byType)->map(fn ($count, $type) => ['type' => $type, 'count' => $count])->values(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'in_maintenance' => (int) ($byStatus['maintenance'] ?? 0),
            'assigned' => VehicleAssignment::where('status', 'active')->whereNull('deleted_at')
                ->whereIn('vehicle_id', $visibleVehicleIds)->distinct('vehicle_id')->count('vehicle_id'),
            'fuel_cost_month' => (float) VehicleFuelLog::whereNull('deleted_at')->whereIn('vehicle_id', $visibleVehicleIds)
                ->whereDate('log_date', '>=', $monthStart->toDateString())->sum('total_cost'),
            'maintenance_cost_month' => (float) VehicleMaintenance::whereNull('deleted_at')->whereIn('vehicle_id', $visibleVehicleIds)
                ->whereDate('service_date', '>=', $monthStart->toDateString())->sum('cost'),
            'fuel_cost_total' => (float) VehicleFuelLog::whereNull('deleted_at')->whereIn('vehicle_id', $visibleVehicleIds)->sum('total_cost'),
            'maintenance_cost_total' => (float) VehicleMaintenance::whereNull('deleted_at')->whereIn('vehicle_id', $visibleVehicleIds)->sum('cost'),
            'alerts' => $this->alerts($today, $soon),
            'cost_trend' => $this->costTrend(),
            'recent_maintenance' => VehicleMaintenance::with('vehicle')->whereNull('deleted_at')
                ->whereIn('vehicle_id', $visibleVehicleIds)
                ->orderBy('service_date', 'desc')->limit(5)->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'vehicle_name' => $m->vehicle ? $m->vehicle->display_name : '',
                    'title' => $m->title,
                    'type' => $m->type,
                    'status' => $m->status,
                    'cost' => (float) $m->cost,
                    'service_date' => optional($m->service_date)->toDateString(),
                ])->values(),
        ]);
    }

    /**
     * Renewals and services that are due or overdue, newest deadline first.
     * `days` is negative when the date has already passed.
     */
    private function alerts(Carbon $today, Carbon $soon)
    {
        $alerts = [];

        $fields = [
            'insurance_expiry' => 'Insurance',
            'registration_expiry' => 'Registration',
            'inspection_expiry' => 'Inspection',
        ];

        foreach ($fields as $field => $label) {
            Vehicle::whereNull('deleted_at')
                ->whereNotNull($field)
                ->whereDate($field, '<=', $soon->toDateString())
                ->orderBy($field)
                ->get()
                ->each(function ($v) use (&$alerts, $field, $label, $today) {
                    $date = Carbon::parse($v->{$field});
                    $alerts[] = [
                        'kind' => $field,
                        'label' => $label,
                        'vehicle_id' => $v->id,
                        'vehicle_name' => $v->display_name,
                        'date' => $date->toDateString(),
                        'days' => $today->diffInDays($date, false),
                    ];
                });
        }

        // Scheduled services that are due by date.
        VehicleMaintenance::with('vehicle')->whereNull('deleted_at')
            ->whereNotNull('next_service_date')
            ->where('status', '!=', 'scheduled')
            ->whereDate('next_service_date', '<=', $soon->toDateString())
            ->orderBy('next_service_date')
            ->get()
            ->each(function ($m) use (&$alerts, $today) {
                if (! $m->vehicle) {
                    return;
                }
                $date = Carbon::parse($m->next_service_date);
                $alerts[] = [
                    'kind' => 'service',
                    'label' => 'Next service',
                    'vehicle_id' => $m->vehicle_id,
                    'vehicle_name' => $m->vehicle->display_name,
                    'date' => $date->toDateString(),
                    'days' => $today->diffInDays($date, false),
                ];
            });

        usort($alerts, fn ($a, $b) => $a['days'] <=> $b['days']);

        return array_slice($alerts, 0, 25);
    }

    /** Fuel vs maintenance spend for the last 6 months, oldest first. */
    private function costTrend()
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::today()->startOfMonth()->subMonths($i);
            $next = $month->copy()->addMonth();

            $months[] = [
                'month' => $month->format('M Y'),
                'fuel' => (float) VehicleFuelLog::whereNull('deleted_at')
                    ->whereDate('log_date', '>=', $month->toDateString())
                    ->whereDate('log_date', '<', $next->toDateString())
                    ->sum('total_cost'),
                'maintenance' => (float) VehicleMaintenance::whereNull('deleted_at')
                    ->whereDate('service_date', '>=', $month->toDateString())
                    ->whereDate('service_date', '<', $next->toDateString())
                    ->sum('cost'),
            ];
        }

        return $months;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Lifetime totals for one vehicle, shown on its detail page. */
    private function vehicleStats(Vehicle $vehicle)
    {
        $fuelCost = (float) VehicleFuelLog::whereNull('deleted_at')->where('vehicle_id', $vehicle->id)->sum('total_cost');
        $fuelQty = (float) VehicleFuelLog::whereNull('deleted_at')->where('vehicle_id', $vehicle->id)->sum('quantity');
        $maintenanceCost = (float) VehicleMaintenance::whereNull('deleted_at')->where('vehicle_id', $vehicle->id)->sum('cost');
        $distance = Vehicle::distanceCovered($vehicle->id);

        return [
            'fuel_cost' => $fuelCost,
            'fuel_quantity' => $fuelQty,
            'maintenance_cost' => $maintenanceCost,
            'total_cost' => $fuelCost + $maintenanceCost,
            'distance' => $distance,
            'cost_per_distance' => $distance > 0 ? round(($fuelCost + $maintenanceCost) / $distance, 2) : null,
            'efficiency' => Vehicle::fuelEfficiency($vehicle->id),
            'maintenance_count' => VehicleMaintenance::whereNull('deleted_at')->where('vehicle_id', $vehicle->id)->count(),
            'fuel_count' => VehicleFuelLog::whereNull('deleted_at')->where('vehicle_id', $vehicle->id)->count(),
            'trip_count' => VehicleAssignment::whereNull('deleted_at')->where('vehicle_id', $vehicle->id)->count(),
        ];
    }

    private function present(Vehicle $vehicle)
    {
        $today = Carbon::today();
        $daysTo = function ($date) use ($today) {
            return $date ? $today->diffInDays(Carbon::parse($date), false) : null;
        };

        return [
            'id' => $vehicle->id,
            'name' => $vehicle->name,
            'plate_number' => $vehicle->plate_number,
            'label' => $vehicle->display_name,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'color' => $vehicle->color,
            'type' => $vehicle->type,
            'status' => $vehicle->status,
            'warehouse_id' => $vehicle->warehouse_id,
            'warehouse_name' => $vehicle->warehouse ? $vehicle->warehouse->name : null,
            'employee_id' => $vehicle->employee_id,
            'driver_name' => $vehicle->driver ? trim($vehicle->driver->firstname . ' ' . $vehicle->driver->lastname) : null,
            'fuel_type' => $vehicle->fuel_type,
            'odometer' => (float) $vehicle->odometer,
            'purchase_date' => optional($vehicle->purchase_date)->toDateString(),
            'purchase_price' => $vehicle->purchase_price === null ? null : (float) $vehicle->purchase_price,
            'insurance_expiry' => optional($vehicle->insurance_expiry)->toDateString(),
            'registration_expiry' => optional($vehicle->registration_expiry)->toDateString(),
            'inspection_expiry' => optional($vehicle->inspection_expiry)->toDateString(),
            'days_to_insurance' => $daysTo($vehicle->insurance_expiry),
            'days_to_registration' => $daysTo($vehicle->registration_expiry),
            'days_to_inspection' => $daysTo($vehicle->inspection_expiry),
            'image' => $vehicle->image,
            'image_url' => $vehicle->image ? asset(self::IMAGE_DIR . '/' . $vehicle->image) : null,
            'created_at' => optional($vehicle->created_at)->toIso8601String(),
        ];
    }

    private function rules()
    {
        return [
            'name' => 'required|string|max:191',
            'plate_number' => 'required|string|max:64',
            'type' => 'required|in:car,van,truck,bus,motorcycle,forklift,trailer,other',
            'status' => 'required|in:active,maintenance,inactive,sold',
            'fuel_type' => 'required|in:petrol,diesel,electric,hybrid,lpg,cng',
            'year' => 'nullable|integer|min:1900|max:2200',
            'odometer' => 'nullable|numeric|min:0',
            'tank_capacity' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'employee_id' => 'nullable|exists:employees,id',
            'purchase_date' => 'nullable|date',
            'insurance_expiry' => 'nullable|date',
            'registration_expiry' => 'nullable|date',
            'inspection_expiry' => 'nullable|date',
            'image' => 'nullable|image|max:5120',
        ];
    }

    private function attributes()
    {
        return [
            'plate_number' => 'plate number',
            'employee_id' => 'driver',
            'warehouse_id' => 'branch',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'name' => $request->name,
            'plate_number' => $request->plate_number,
            'vin' => $request->vin,
            'make' => $request->make,
            'model' => $request->model,
            'year' => $request->year ?: null,
            'color' => $request->color,
            'type' => $request->type,
            'status' => $request->status,
            'warehouse_id' => $request->warehouse_id ?: null,
            'employee_id' => $request->employee_id ?: null,
            'fuel_type' => $request->fuel_type,
            'tank_capacity' => $request->tank_capacity ?: null,
            'odometer' => $request->odometer ?: 0,
            'purchase_date' => $request->purchase_date ?: null,
            'purchase_price' => $request->purchase_price ?: null,
            'insurance_provider' => $request->insurance_provider,
            'insurance_policy' => $request->insurance_policy,
            'insurance_expiry' => $request->insurance_expiry ?: null,
            'registration_expiry' => $request->registration_expiry ?: null,
            'inspection_expiry' => $request->inspection_expiry ?: null,
            'notes' => $request->notes,
        ];
    }

    /** Returns the stored file name, or null when no photo was sent. */
    private function storeImage(Request $request)
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $file = $request->file('image');
        $path = public_path(self::IMAGE_DIR);
        if (! file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
        $filename = time() . '_' . Str::random(10) . '.' . $extension;
        $file->move($path, $filename);

        return $filename;
    }

    private function deleteImage($filename)
    {
        if (! $filename) {
            return;
        }
        $path = public_path(self::IMAGE_DIR . '/' . $filename);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
