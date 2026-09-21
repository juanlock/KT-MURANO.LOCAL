<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\HospitalBed;
use App\Models\HospitalWard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Wards and the beds inside them.
 *
 * A bed's `status` is authoritative for availability, and only the admission
 * controller sets it to `occupied` — this controller refuses to delete or
 * re-open a bed that an open admission is holding, so the board can never show
 * an empty bed that has a patient in it.
 */
class HospitalWardController extends Controller
{
    private const SORTABLE = ['id', 'name', 'type', 'floor', 'daily_rate', 'is_active', 'created_at'];

    // ------------------------------------------------------------------
    // Wards
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', HospitalWard::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'name';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = HospitalWard::with('department')->whereNull('deleted_at');

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $query->where('name', 'LIKE', "%{$request->search}%");
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();
        $counts = $this->bedCounts($rows->pluck('id')->all());

        return response()->json([
            'totalRows' => $totalRows,
            'wards' => $rows->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'department_id' => $w->department_id,
                'department_name' => $w->department ? $w->department->name : null,
                'type' => $w->type,
                'floor' => $w->floor,
                'daily_rate' => (float) $w->daily_rate,
                'is_active' => (bool) $w->is_active,
                'notes' => $w->notes,
                'beds_total' => (int) ($counts[$w->id]['total'] ?? 0),
                'beds_occupied' => (int) ($counts[$w->id]['occupied'] ?? 0),
                'beds_available' => (int) ($counts[$w->id]['available'] ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', HospitalWard::class);

        $request->validate($this->wardRules());
        HospitalWard::create($this->wardPayload($request));

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', HospitalWard::class);

        $request->validate($this->wardRules());
        HospitalWard::whereNull('deleted_at')->findOrFail($id)->update($this->wardPayload($request));

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', HospitalWard::class);

        $ward = HospitalWard::whereNull('deleted_at')->findOrFail($id);

        $occupied = HospitalBed::whereNull('deleted_at')->where('ward_id', $ward->id)->where('status', 'occupied')->count();
        if ($occupied) {
            return response()->json([
                'message' => "This ward still has {$occupied} occupied bed(s). Discharge or transfer those patients first.",
            ], 422);
        }

        HospitalBed::where('ward_id', $ward->id)->delete();
        $ward->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Beds
    // ------------------------------------------------------------------

    /** The bed board: every bed grouped by ward, with who is in it. */
    public function beds(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', HospitalWard::class);

        $wards = HospitalWard::with('department')->whereNull('deleted_at')
            ->when($request->filled('ward_id'), fn ($q) => $q->where('id', $request->ward_id))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->orderBy('name')->get();

        $beds = HospitalBed::whereNull('deleted_at')->whereIn('ward_id', $wards->pluck('id'))
            ->orderBy('bed_number')->get();

        // Who currently holds each bed, in one query.
        $occupants = Admission::with('patient')->whereNull('deleted_at')
            ->where('status', 'admitted')
            ->whereIn('bed_id', $beds->pluck('id'))
            ->get()
            ->keyBy('bed_id');

        return response()->json([
            'wards' => $wards->map(function ($ward) use ($beds, $occupants) {
                $wardBeds = $beds->where('ward_id', $ward->id)->values();

                return [
                    'id' => $ward->id,
                    'name' => $ward->name,
                    'type' => $ward->type,
                    'floor' => $ward->floor,
                    'department_name' => $ward->department ? $ward->department->name : null,
                    'daily_rate' => (float) $ward->daily_rate,
                    'beds' => $wardBeds->map(function ($bed) use ($occupants) {
                        $admission = $occupants->get($bed->id);

                        return [
                            'id' => $bed->id,
                            'bed_number' => $bed->bed_number,
                            'status' => $bed->status,
                            'daily_rate' => $bed->effective_rate,
                            'notes' => $bed->notes,
                            'admission_id' => $admission ? $admission->id : null,
                            'patient_id' => $admission ? $admission->patient_id : null,
                            'patient_name' => $admission && $admission->patient ? $admission->patient->name : null,
                            'admitted_at' => $admission ? optional($admission->admitted_at)->toIso8601String() : null,
                        ];
                    })->values(),
                ];
            })->values(),
        ]);
    }

    public function storeBed(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', HospitalWard::class);

        $request->validate($this->bedRules());

        // Adding a range at once: wards are built in blocks, not one bed at a time.
        $numbers = $this->expandBedNumbers($request->bed_number);
        $created = 0;
        foreach ($numbers as $number) {
            $exists = HospitalBed::whereNull('deleted_at')
                ->where('ward_id', $request->ward_id)->where('bed_number', $number)->exists();
            if ($exists) {
                continue;
            }
            HospitalBed::create([
                'ward_id' => $request->ward_id,
                'bed_number' => $number,
                'status' => $request->status ?: 'available',
                'daily_rate' => $request->filled('daily_rate') ? $request->daily_rate : null,
                'notes' => $request->notes,
            ]);
            $created++;
        }

        if (! $created) {
            return response()->json(['message' => 'Those bed numbers already exist in this ward.'], 422);
        }

        return response()->json(['success' => true, 'count' => $created]);
    }

    public function updateBed(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', HospitalWard::class);

        $bed = HospitalBed::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->bedRules(true));

        // The admission controller owns 'occupied'; nothing else may set or
        // clear it, or the board would disagree with reality.
        $status = $request->status ?: $bed->status;
        if ($bed->status === 'occupied' && $status !== 'occupied') {
            return response()->json([
                'message' => 'This bed is occupied. Discharge or transfer the patient to free it.',
            ], 422);
        }
        if ($bed->status !== 'occupied' && $status === 'occupied') {
            return response()->json([
                'message' => 'A bed becomes occupied by admitting a patient, not by editing it.',
            ], 422);
        }

        $bed->update([
            'bed_number' => $request->bed_number ?: $bed->bed_number,
            'status' => $status,
            'daily_rate' => $request->filled('daily_rate') ? $request->daily_rate : null,
            'notes' => $request->notes,
        ]);

        return response()->json(['success' => true]);
    }

    public function destroyBed(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', HospitalWard::class);

        $bed = HospitalBed::whereNull('deleted_at')->findOrFail($id);
        if ($bed->status === 'occupied') {
            return response()->json([
                'message' => 'This bed is occupied. Discharge or transfer the patient first.',
            ], 422);
        }

        $bed->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function bedCounts(array $wardIds)
    {
        if (! $wardIds) {
            return [];
        }

        $rows = HospitalBed::whereNull('deleted_at')->whereIn('ward_id', $wardIds)
            ->select('ward_id', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('ward_id', 'status')->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->ward_id]['total'] = ($out[$row->ward_id]['total'] ?? 0) + (int) $row->aggregate;
            $out[$row->ward_id][$row->status] = (int) $row->aggregate;
        }

        return $out;
    }

    /** "12" -> [12]; "1-4" -> [1,2,3,4]; "A1,A2" -> [A1,A2]. */
    private function expandBedNumbers($input)
    {
        $input = trim((string) $input);

        if (strpos($input, ',') !== false) {
            return array_values(array_filter(array_map('trim', explode(',', $input)), 'strlen'));
        }

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $input, $m)) {
            $from = (int) $m[1];
            $to = (int) $m[2];
            if ($to >= $from && ($to - $from) <= 200) {
                return array_map('strval', range($from, $to));
            }
        }

        return $input === '' ? [] : [$input];
    }

    private function wardRules()
    {
        return [
            'name' => 'required|string|max:191',
            'type' => 'required|in:general,private,semi_private,icu,nicu,maternity,isolation',
            'department_id' => 'nullable|exists:hospital_departments,id',
            'daily_rate' => 'nullable|numeric|min:0',
        ];
    }

    private function wardPayload(Request $request)
    {
        return [
            'name' => $request->name,
            'department_id' => $request->department_id ?: null,
            'type' => $request->type,
            'floor' => $request->floor,
            'daily_rate' => $request->daily_rate ?: 0,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $request->notes,
        ];
    }

    private function bedRules($isUpdate = false)
    {
        return [
            'ward_id' => ($isUpdate ? 'nullable' : 'required') . '|exists:hospital_wards,id',
            'bed_number' => ($isUpdate ? 'nullable' : 'required') . '|string|max:40',
            'status' => 'nullable|in:available,occupied,maintenance,reserved',
            'daily_rate' => 'nullable|numeric|min:0',
        ];
    }
}
