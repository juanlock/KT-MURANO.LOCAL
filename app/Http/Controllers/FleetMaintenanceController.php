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
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    private const SORTABLE = ['id', 'service_date', 'type', 'status', 'cost', 'odometer', 'next_service_date', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', VehicleMaintenance::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'service_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = VehicleMaintenance::with('vehicle')->whereNull('deleted_at');
        // Multicompany (2026-11-01: this list showed every company's work): the vehicles of the
        // list's company (AdminSystem = every company).
        $list = $this->listCompanyId($request);
        if ($list !== null) {
            $query->whereHas('vehicle', fn ($v) => $v->where('company_id', $list));
        }

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
        $providers = \Illuminate\Support\Facades\DB::table('providers')->whereIn('id', $rows->pluck('provider_id')->filter())->pluck('name', 'id');
        $mechanics = \Illuminate\Support\Facades\DB::table('employees')->whereIn('id', $rows->pluck('mechanic_id')->filter())
            ->get(['id', 'firstname', 'lastname'])->mapWithKeys(fn ($e) => [$e->id => trim($e->firstname.' '.$e->lastname)]);

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
                'performed_by' => $m->performed_by ?: 'in_house',
                'provider_id' => $m->provider_id,
                'provider_name' => $providers[$m->provider_id] ?? null,
                'mechanic_id' => $m->mechanic_id,
                'mechanic_name' => $mechanics[$m->mechanic_id] ?? null,
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
        $this->checkCompany($request);

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
        $this->checkCompany($request);

        $maintenance = VehicleMaintenance::whereNull('deleted_at')->findOrFail($id);
        abort_unless($this->vehicleAllowed($request, (int) $maintenance->vehicle_id), 404);
        $maintenance->update($this->payload($request));

        $this->syncVehicleOdometer($maintenance);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', VehicleMaintenance::class);

        $m = VehicleMaintenance::whereNull('deleted_at')->findOrFail($id);
        abort_unless($this->vehicleAllowed($request, (int) $m->vehicle_id), 404);
        $m->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', VehicleMaintenance::class);

        $ids = (array) $request->selectedIds;
        $list = $this->listCompanyId($request);
        VehicleMaintenance::whereIn('id', $ids)
            ->when($list !== null, fn ($q) => $q->whereHas('vehicle', fn ($v) => $v->where('company_id', $list)))->delete();

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
            'performed_by' => 'nullable|in:in_house,workshop',
            'provider_id' => 'nullable|integer|required_if:performed_by,workshop',
            'mechanic_id' => 'nullable|integer',
        ];
    }

    /** The vehicle is one of the list's company (AdminSystem: any). */
    private function vehicleAllowed(Request $request, int $vehicleId): bool
    {
        $list = $this->listCompanyId($request);

        return $list === null || Vehicle::where('id', $vehicleId)->where('company_id', $list)->exists();
    }

    /** Vehicle, workshop (supplier) and mechanic (employee) of the vehicle's company. */
    private function checkCompany(Request $request): void
    {
        $vehicle = Vehicle::whereNull('deleted_at')->find($request->vehicle_id);
        abort_unless($vehicle && $this->vehicleAllowed($request, (int) $vehicle->id), 422, 'Choose a vehicle of the company.');
        $cid = (int) $vehicle->company_id;
        if ($request->filled('provider_id')) {
            abort_unless(\Illuminate\Support\Facades\DB::table('providers')->where('id', $request->provider_id)->where('company_id', $cid)->whereNull('deleted_at')->exists(),
                422, 'Choose a workshop from the company\'s suppliers.');
        }
        if ($request->filled('mechanic_id')) {
            abort_unless(\Illuminate\Support\Facades\DB::table('employees')->where('id', $request->mechanic_id)->where('company_id', $cid)->whereNull('deleted_at')->exists(),
                422, 'Choose a mechanic from the company\'s employees.');
        }
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
            'performed_by' => $request->performed_by === 'workshop' ? 'workshop' : 'in_house',
            'provider_id' => $request->performed_by === 'workshop' ? ($request->provider_id ?: null) : null,
            'mechanic_id' => $request->performed_by === 'workshop' ? null : ($request->mechanic_id ?: null),
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
