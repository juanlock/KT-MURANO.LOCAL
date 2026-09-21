<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\HospitalBed;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Inpatient admissions, discharges and bed transfers.
 *
 * This controller is the ONLY thing that sets a bed to `occupied` or releases
 * it, so the bed board can be trusted: admitting takes the bed, discharging
 * frees it, transferring hands it over atomically. Every one of those runs in a
 * transaction — a half-applied transfer would leave a patient in two beds or
 * none.
 */
class HospitalAdmissionController extends Controller
{
    private const SORTABLE = ['id', 'reference', 'admitted_at', 'discharged_at', 'status', 'daily_rate', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Admission::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'admitted_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Admission::with('patient', 'doctor', 'ward', 'bed')->whereNull('deleted_at');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }
        if ($request->filled('ward_id')) {
            $query->where('ward_id', $request->ward_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('admitted_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('admitted_at', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('diagnosis', 'LIKE', "%{$search}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('mrn', 'LIKE', "%{$search}%"));
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
            'admissions' => $rows->map(fn ($a) => $this->present($a))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Admission::class);

        $request->validate($this->rules());

        // One open admission per patient — a person occupies one bed at a time.
        $alreadyIn = Admission::whereNull('deleted_at')
            ->where('patient_id', $request->patient_id)->where('status', 'admitted')->first();
        if ($alreadyIn) {
            return response()->json([
                'message' => 'This patient is already admitted (' . $alreadyIn->reference . '). Discharge or transfer them first.',
            ], 422);
        }

        $userId = optional($request->user('api'))->id;

        try {
            $admission = DB::transaction(function () use ($request, $userId) {
                $bed = $this->lockBed($request->bed_id);

                $data = $this->payload($request);
                $data['reference'] = Admission::nextReference('ADM');
                $data['status'] = 'admitted';
                $data['created_by'] = $userId;
                // Fall back to the bed's rate so a stay is never billed at zero
                // just because nobody retyped the price.
                if (! $request->filled('daily_rate')) {
                    $data['daily_rate'] = $bed ? $bed->effective_rate : 0;
                }
                if ($bed) {
                    $data['ward_id'] = $bed->ward_id;
                }

                $admission = Admission::create($data);

                if ($bed) {
                    $bed->update(['status' => 'occupied']);
                }

                return $admission;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'id' => $admission->id, 'reference' => $admission->reference]);
    }

    /** Edits the clinical details; the bed is moved with transfer(). */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Admission::class);

        $admission = Admission::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->rules(true));

        $data = $this->payload($request);
        unset($data['bed_id'], $data['ward_id']);
        $admission->update($data);

        return response()->json(['success' => true]);
    }

    public function discharge(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Admission::class);

        $request->validate([
            'discharged_at' => 'nullable|date',
            'status' => 'nullable|in:discharged,transferred,deceased',
            'discharge_summary' => 'nullable|string',
        ]);

        $admission = Admission::whereNull('deleted_at')->findOrFail($id);
        if ($admission->status !== 'admitted') {
            return response()->json(['message' => 'This admission is already closed.'], 422);
        }

        DB::transaction(function () use ($request, $admission) {
            $admission->update([
                'status' => $request->status ?: 'discharged',
                'discharged_at' => $request->discharged_at ?: now(),
                'discharge_summary' => $request->discharge_summary ?: $admission->discharge_summary,
            ]);

            if ($admission->bed_id) {
                HospitalBed::where('id', $admission->bed_id)
                    ->where('status', 'occupied')
                    ->update(['status' => 'available']);
            }
        });

        return response()->json([
            'success' => true,
            'nights' => $admission->fresh()->nights,
            'bed_charge' => $admission->fresh()->bed_charge,
        ]);
    }

    /** Move an admitted patient to another bed, freeing the old one. */
    public function transfer(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Admission::class);

        $request->validate([
            'bed_id' => 'required|exists:hospital_beds,id',
            'daily_rate' => 'nullable|numeric|min:0',
        ]);

        $admission = Admission::whereNull('deleted_at')->findOrFail($id);
        if ($admission->status !== 'admitted') {
            return response()->json(['message' => 'Only an open admission can be transferred.'], 422);
        }
        if ((int) $admission->bed_id === (int) $request->bed_id) {
            return response()->json(['message' => 'The patient is already in that bed.'], 422);
        }

        try {
            DB::transaction(function () use ($request, $admission) {
                $bed = $this->lockBed($request->bed_id);
                $previousBedId = $admission->bed_id;

                $admission->update([
                    'bed_id' => $bed->id,
                    'ward_id' => $bed->ward_id,
                    'daily_rate' => $request->filled('daily_rate') ? $request->daily_rate : $bed->effective_rate,
                ]);

                $bed->update(['status' => 'occupied']);

                if ($previousBedId) {
                    HospitalBed::where('id', $previousBedId)
                        ->where('status', 'occupied')
                        ->update(['status' => 'available']);
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Admission::class);

        $admission = Admission::whereNull('deleted_at')->findOrFail($id);

        DB::transaction(function () use ($admission) {
            // Deleting an open admission must hand the bed back.
            if ($admission->status === 'admitted' && $admission->bed_id) {
                HospitalBed::where('id', $admission->bed_id)
                    ->where('status', 'occupied')
                    ->update(['status' => 'available']);
            }
            $admission->delete();
        });

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Fetch a bed FOR UPDATE and refuse it unless it is genuinely free. The row
     * lock is what stops two clerks admitting different patients into the same
     * bed in the same second.
     */
    private function lockBed($bedId)
    {
        if (! $bedId) {
            return null;
        }

        $bed = HospitalBed::whereNull('deleted_at')->lockForUpdate()->find($bedId);

        if (! $bed) {
            throw new \RuntimeException('That bed no longer exists.');
        }
        if ($bed->status === 'occupied') {
            throw new \RuntimeException('That bed has just been taken. Pick another one.');
        }
        if (in_array($bed->status, ['maintenance'], true)) {
            throw new \RuntimeException('That bed is out of service.');
        }

        return $bed;
    }

    private function present(Admission $a)
    {
        return [
            'id' => $a->id,
            'reference' => $a->reference,
            'patient_id' => $a->patient_id,
            'patient_name' => $a->patient ? $a->patient->name : '',
            'patient_mrn' => $a->patient ? $a->patient->mrn : '',
            'doctor_id' => $a->doctor_id,
            'doctor_name' => $a->doctor ? $a->doctor->name : null,
            'ward_id' => $a->ward_id,
            'ward_name' => $a->ward ? $a->ward->name : null,
            'bed_id' => $a->bed_id,
            'bed_number' => $a->bed ? $a->bed->bed_number : null,
            'department_id' => $a->department_id,
            'admitted_at' => optional($a->admitted_at)->toIso8601String(),
            'discharged_at' => optional($a->discharged_at)->toIso8601String(),
            'nights' => $a->nights,
            'daily_rate' => (float) $a->daily_rate,
            'bed_charge' => $a->bed_charge,
            'reason' => $a->reason,
            'diagnosis' => $a->diagnosis,
            'discharge_summary' => $a->discharge_summary,
            'status' => $a->status,
        ];
    }

    private function rules($isUpdate = false)
    {
        return [
            'patient_id' => 'required|exists:patients,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'bed_id' => ($isUpdate ? 'nullable' : 'required') . '|exists:hospital_beds,id',
            'department_id' => 'nullable|exists:hospital_departments,id',
            'admitted_at' => 'required|date',
            'daily_rate' => 'nullable|numeric|min:0',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'patient_id' => $request->patient_id,
            'doctor_id' => $request->doctor_id ?: null,
            'bed_id' => $request->bed_id ?: null,
            'department_id' => $request->department_id ?: null,
            'admitted_at' => $request->admitted_at,
            'daily_rate' => $request->filled('daily_rate') ? $request->daily_rate : 0,
            'reason' => $request->reason,
            'diagnosis' => $request->diagnosis,
        ];
    }
}
