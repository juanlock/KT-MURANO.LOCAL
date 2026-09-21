<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Http\Request;

/**
 * Service and repair log. Recording work with a higher odometer reading than
 * the vehicle currently holds pushes the vehicle's reading forward — the log is
 * the freshest information the system has about where the vehicle is.
 */
class FleetMaintenanceController extends Controller
{
    private const SORTABLE = ['id', 'service_date', 'type', 'status', 'cost', 'odometer', 'next_service_date', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', VehicleMaintenance::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'service_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = VehicleMaintenance::with('vehicle')->whereNull('deleted_at');

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('service_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('service_date', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('vendor', 'LIKE', "%{$search}%")
                    ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('plate_number', 'LIKE', "%{$search}%"));
            });
        }

        $totalRows = $query->count();
        $totalCost = (float) $query->sum('cost');
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'total_cost' => $totalCost,
            'maintenances' => $rows->map(fn ($m) => [
                'id' => $m->id,
                'vehicle_id' => $m->vehicle_id,
                'vehicle_name' => $m->vehicle ? $m->vehicle->display_name : '',
                'type' => $m->type,
                'title' => $m->title,
                'service_date' => optional($m->service_date)->toDateString(),
                'odometer' => $m->odometer === null ? null : (float) $m->odometer,
                'cost' => (float) $m->cost,
                'vendor' => $m->vendor,
                'status' => $m->status,
                'next_service_date' => optional($m->next_service_date)->toDateString(),
                'next_service_odometer' => $m->next_service_odometer === null ? null : (float) $m->next_service_odometer,
                'notes' => $m->notes,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', VehicleMaintenance::class);

        $request->validate($this->rules());

        $maintenance = VehicleMaintenance::create($this->payload($request) + [
            'created_by' => optional($request->user('api'))->id,
        ]);

        $this->syncVehicleOdometer($maintenance);

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', VehicleMaintenance::class);

        $request->validate($this->rules());

        $maintenance = VehicleMaintenance::whereNull('deleted_at')->findOrFail($id);
        $maintenance->update($this->payload($request));

        $this->syncVehicleOdometer($maintenance);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', VehicleMaintenance::class);

        VehicleMaintenance::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', VehicleMaintenance::class);

        $ids = (array) $request->selectedIds;
        VehicleMaintenance::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    private function rules()
    {
        return [
            'vehicle_id' => 'required|exists:vehicles,id',
            'type' => 'required|in:service,repair,tyres,inspection,insurance,other',
            'title' => 'required|string|max:191',
            'service_date' => 'required|date',
            'status' => 'required|in:scheduled,in_progress,completed',
            'cost' => 'nullable|numeric|min:0',
            'odometer' => 'nullable|numeric|min:0',
            'next_service_odometer' => 'nullable|numeric|min:0',
            'next_service_date' => 'nullable|date',
            'vendor' => 'nullable|string|max:191',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'vehicle_id' => $request->vehicle_id,
            'type' => $request->type,
            'title' => $request->title,
            'service_date' => $request->service_date,
            'odometer' => $request->odometer !== null && $request->odometer !== '' ? $request->odometer : null,
            'cost' => $request->cost ?: 0,
            'vendor' => $request->vendor,
            'status' => $request->status,
            'next_service_date' => $request->next_service_date ?: null,
            'next_service_odometer' => $request->next_service_odometer ?: null,
            'notes' => $request->notes,
        ];
    }

    /** Never moves a vehicle's odometer backwards. */
    private function syncVehicleOdometer(VehicleMaintenance $maintenance)
    {
        if (! $maintenance->odometer) {
            return;
        }

        $vehicle = Vehicle::find($maintenance->vehicle_id);
        if ($vehicle && (float) $maintenance->odometer > (float) $vehicle->odometer) {
            $vehicle->update(['odometer' => $maintenance->odometer]);
        }
    }
}
