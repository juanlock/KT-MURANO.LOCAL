<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;

/**
 * Driver assignments / trips.
 *
 * A vehicle can only be out with one driver at a time: creating an active
 * assignment for a vehicle that already has one is rejected (422) rather than
 * silently double-booked. Starting an assignment also sets the vehicle's
 * current driver; closing it clears the driver and rolls the odometer forward.
 */
class FleetAssignmentController extends Controller
{
    private const SORTABLE = ['id', 'start_date', 'end_date', 'status', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', VehicleAssignment::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'start_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = VehicleAssignment::with('vehicle', 'driver')->whereNull('deleted_at');

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('start_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('start_date', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('purpose', 'LIKE', "%{$search}%")
                    ->orWhere('destination', 'LIKE', "%{$search}%")
                    ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('plate_number', 'LIKE', "%{$search}%"));
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'assignments' => $rows->map(fn ($a) => [
                'id' => $a->id,
                'vehicle_id' => $a->vehicle_id,
                'vehicle_name' => $a->vehicle ? $a->vehicle->display_name : '',
                'employee_id' => $a->employee_id,
                'driver_name' => $a->driver ? trim($a->driver->firstname . ' ' . $a->driver->lastname) : '',
                'start_date' => optional($a->start_date)->toIso8601String(),
                'end_date' => optional($a->end_date)->toIso8601String(),
                'start_odometer' => $a->start_odometer === null ? null : (float) $a->start_odometer,
                'end_odometer' => $a->end_odometer === null ? null : (float) $a->end_odometer,
                'distance' => $a->distance,
                'purpose' => $a->purpose,
                'destination' => $a->destination,
                'status' => $a->status,
                'notes' => $a->notes,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', VehicleAssignment::class);

        $request->validate($this->rules());

        if ($request->status === 'active' && $this->hasActiveAssignment($request->vehicle_id)) {
            return response()->json([
                'message' => 'This vehicle is already assigned. Close the open assignment first.',
            ], 422);
        }

        $assignment = VehicleAssignment::create($this->payload($request) + [
            'created_by' => optional($request->user('api'))->id,
        ]);

        $this->syncVehicle($assignment);

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', VehicleAssignment::class);

        $request->validate($this->rules());

        $assignment = VehicleAssignment::whereNull('deleted_at')->findOrFail($id);

        if ($request->status === 'active' && $this->hasActiveAssignment($request->vehicle_id, $assignment->id)) {
            return response()->json([
                'message' => 'This vehicle is already assigned. Close the open assignment first.',
            ], 422);
        }

        $assignment->update($this->payload($request));
        $this->syncVehicle($assignment);

        return response()->json(['success' => true]);
    }

    /** Return the vehicle: close the trip with its final reading. */
    public function close(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', VehicleAssignment::class);

        $request->validate([
            'end_odometer' => 'nullable|numeric|min:0',
            'end_date' => 'nullable|date',
        ]);

        $assignment = VehicleAssignment::whereNull('deleted_at')->findOrFail($id);
        $assignment->update([
            'status' => 'completed',
            'end_date' => $request->end_date ?: now(),
            'end_odometer' => $request->filled('end_odometer') ? $request->end_odometer : $assignment->end_odometer,
        ]);

        $this->syncVehicle($assignment);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', VehicleAssignment::class);

        VehicleAssignment::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', VehicleAssignment::class);

        $ids = (array) $request->selectedIds;
        VehicleAssignment::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    private function hasActiveAssignment($vehicleId, $exceptId = null)
    {
        return VehicleAssignment::whereNull('deleted_at')
            ->where('vehicle_id', $vehicleId)
            ->where('status', 'active')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    private function rules()
    {
        return [
            'vehicle_id' => 'required|exists:vehicles,id',
            'employee_id' => 'required|exists:employees,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|in:active,completed,cancelled',
            'start_odometer' => 'nullable|numeric|min:0',
            'end_odometer' => 'nullable|numeric|min:0',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'vehicle_id' => $request->vehicle_id,
            'employee_id' => $request->employee_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date ?: null,
            'start_odometer' => $request->filled('start_odometer') ? $request->start_odometer : null,
            'end_odometer' => $request->filled('end_odometer') ? $request->end_odometer : null,
            'purpose' => $request->purpose,
            'destination' => $request->destination,
            'status' => $request->status,
            'notes' => $request->notes,
        ];
    }

    /**
     * Keep the vehicle in step: an open assignment owns the driver field, a
     * closed one hands the vehicle back and pushes the odometer forward.
     */
    private function syncVehicle(VehicleAssignment $assignment)
    {
        $vehicle = Vehicle::find($assignment->vehicle_id);
        if (! $vehicle) {
            return;
        }

        $changes = [];

        if ($assignment->status === 'active') {
            $changes['employee_id'] = $assignment->employee_id;
        } elseif ($vehicle->employee_id === $assignment->employee_id) {
            $changes['employee_id'] = null;
        }

        if ($assignment->end_odometer && (float) $assignment->end_odometer > (float) $vehicle->odometer) {
            $changes['odometer'] = $assignment->end_odometer;
        }

        if ($changes) {
            $vehicle->update($changes);
        }
    }
}
