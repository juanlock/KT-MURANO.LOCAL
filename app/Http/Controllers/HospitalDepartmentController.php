<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\HospitalDepartment;
use App\Models\HospitalWard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Clinical departments (Cardiology, Paediatrics...). Deliberately separate from
 * the HR `departments` table: an HR department is a payroll unit, a clinical
 * department is where a patient is treated, and conflating them breaks both.
 */
class HospitalDepartmentController extends Controller
{
    private const SORTABLE = ['id', 'name', 'code', 'is_active', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', HospitalDepartment::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'name';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = HospitalDepartment::whereNull('deleted_at');

        if ($request->filled('active')) {
            $query->where('is_active', $request->active === '1' ? 1 : 0);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%")
                    ->orWhere('location', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        // Counts in two queries rather than two per row.
        $doctorCounts = Doctor::whereNull('deleted_at')->whereIn('department_id', $rows->pluck('id'))
            ->select('department_id', DB::raw('count(*) as aggregate'))->groupBy('department_id')
            ->pluck('aggregate', 'department_id')->toArray();
        $wardCounts = HospitalWard::whereNull('deleted_at')->whereIn('department_id', $rows->pluck('id'))
            ->select('department_id', DB::raw('count(*) as aggregate'))->groupBy('department_id')
            ->pluck('aggregate', 'department_id')->toArray();

        return response()->json([
            'totalRows' => $totalRows,
            'departments' => $rows->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'description' => $d->description,
                'location' => $d->location,
                'phone' => $d->phone,
                'is_active' => (bool) $d->is_active,
                'doctors_count' => (int) ($doctorCounts[$d->id] ?? 0),
                'wards_count' => (int) ($wardCounts[$d->id] ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', HospitalDepartment::class);

        $request->validate($this->rules());
        HospitalDepartment::create($this->payload($request));

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', HospitalDepartment::class);

        $request->validate($this->rules());
        HospitalDepartment::whereNull('deleted_at')->findOrFail($id)->update($this->payload($request));

        return response()->json(['success' => true]);
    }

    /**
     * Doctors and wards are detached rather than deleted — losing a
     * department must never quietly remove the practitioners inside it.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', HospitalDepartment::class);

        $department = HospitalDepartment::whereNull('deleted_at')->findOrFail($id);
        Doctor::where('department_id', $department->id)->update(['department_id' => null]);
        HospitalWard::where('department_id', $department->id)->update(['department_id' => null]);
        $department->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', HospitalDepartment::class);

        $ids = (array) $request->selectedIds;
        Doctor::whereIn('department_id', $ids)->update(['department_id' => null]);
        HospitalWard::whereIn('department_id', $ids)->update(['department_id' => null]);
        HospitalDepartment::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    private function rules()
    {
        return [
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:32',
            'phone' => 'nullable|string|max:40',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'name' => $request->name,
            'code' => $request->code,
            'description' => $request->description,
            'location' => $request->location,
            'phone' => $request->phone,
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
