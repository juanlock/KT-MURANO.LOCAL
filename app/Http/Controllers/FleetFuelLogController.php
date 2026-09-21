<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use Illuminate\Http\Request;

/**
 * Fuel log. `total_cost` is derived from quantity x unit price when the client
 * doesn't send it, so a till receipt can be entered either way round.
 */
class FleetFuelLogController extends Controller
{
    private const SORTABLE = ['id', 'log_date', 'odometer', 'quantity', 'unit_price', 'total_cost', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', VehicleFuelLog::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'log_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = VehicleFuelLog::with('vehicle', 'driver')->whereNull('deleted_at');

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('log_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('log_date', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('station', 'LIKE', "%{$search}%")
                    ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('plate_number', 'LIKE', "%{$search}%"));
            });
        }

        $totalRows = $query->count();
        $totals = [
            'cost' => (float) $query->sum('total_cost'),
            'quantity' => (float) $query->sum('quantity'),
        ];
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'totals' => $totals,
            'fuel_logs' => $rows->map(fn ($f) => [
                'id' => $f->id,
                'vehicle_id' => $f->vehicle_id,
                'vehicle_name' => $f->vehicle ? $f->vehicle->display_name : '',
                'employee_id' => $f->employee_id,
                'driver_name' => $f->driver ? trim($f->driver->firstname . ' ' . $f->driver->lastname) : null,
                'log_date' => optional($f->log_date)->toDateString(),
                'odometer' => (float) $f->odometer,
                'quantity' => (float) $f->quantity,
                'unit_price' => (float) $f->unit_price,
                'total_cost' => (float) $f->total_cost,
                'station' => $f->station,
                'full_tank' => (bool) $f->full_tank,
                'notes' => $f->notes,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', VehicleFuelLog::class);

        $request->validate($this->rules());

        $log = VehicleFuelLog::create($this->payload($request) + [
            'created_by' => optional($request->user('api'))->id,
        ]);

        $this->syncVehicleOdometer($log);

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', VehicleFuelLog::class);

        $request->validate($this->rules());

        $log = VehicleFuelLog::whereNull('deleted_at')->findOrFail($id);
        $log->update($this->payload($request));

        $this->syncVehicleOdometer($log);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', VehicleFuelLog::class);

        VehicleFuelLog::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', VehicleFuelLog::class);

        $ids = (array) $request->selectedIds;
        VehicleFuelLog::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    private function rules()
    {
        return [
            'vehicle_id' => 'required|exists:vehicles,id',
            'employee_id' => 'nullable|exists:employees,id',
            'log_date' => 'required|date',
            'odometer' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0.01',
            'unit_price' => 'nullable|numeric|min:0',
            'total_cost' => 'nullable|numeric|min:0',
            'station' => 'nullable|string|max:191',
        ];
    }

    private function payload(Request $request)
    {
        $quantity = (float) $request->quantity;
        $unitPrice = (float) $request->unit_price;
        // Enter either the pump price or the receipt total; the other follows.
        $total = $request->filled('total_cost') ? (float) $request->total_cost : $quantity * $unitPrice;
        if (! $request->filled('unit_price') && $quantity > 0) {
            $unitPrice = round($total / $quantity, 2);
        }

        return [
            'vehicle_id' => $request->vehicle_id,
            'employee_id' => $request->employee_id ?: null,
            'log_date' => $request->log_date,
            'odometer' => $request->odometer,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_cost' => $total,
            'station' => $request->station,
            'full_tank' => $request->boolean('full_tank'),
            'notes' => $request->notes,
        ];
    }

    /** Never moves a vehicle's odometer backwards. */
    private function syncVehicleOdometer(VehicleFuelLog $log)
    {
        $vehicle = Vehicle::find($log->vehicle_id);
        if ($vehicle && (float) $log->odometer > (float) $vehicle->odometer) {
            $vehicle->update(['odometer' => $log->odometer]);
        }
    }
}
