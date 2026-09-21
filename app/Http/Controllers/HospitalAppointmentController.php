<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Appointment diary.
 *
 * Double-booking is refused: a doctor cannot hold two overlapping appointments
 * whose statuses still occupy the diary (scheduled/confirmed/arrived). Cancelled
 * and completed slots are free again, which is what makes rebooking work.
 */
class HospitalAppointmentController extends Controller
{
    private const SORTABLE = ['id', 'reference', 'scheduled_at', 'status', 'type', 'fee', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Appointment::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'scheduled_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Appointment::with('patient', 'doctor', 'department')->whereNull('deleted_at');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->boolean('today')) {
            $query->whereDate('scheduled_at', Carbon::today()->toDateString());
        }
        if ($request->filled('start_date')) {
            $query->whereDate('scheduled_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('scheduled_at', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('reason', 'LIKE', "%{$search}%")
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
            'appointments' => $rows->map(fn ($a) => $this->present($a))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Appointment::class);

        $request->validate($this->rules());

        if ($clash = $this->findClash($request)) {
            return response()->json([
                'message' => 'This doctor already has an appointment at that time (' . $clash->reference . ').',
            ], 422);
        }

        $data = $this->payload($request);
        $data['reference'] = Appointment::nextReference('APT');
        $data['created_by'] = optional($request->user('api'))->id;

        // Default the fee to the doctor's standard consultation charge.
        if (! $request->filled('fee')) {
            $doctor = Doctor::find($request->doctor_id);
            $data['fee'] = $doctor ? (float) $doctor->consultation_fee : 0;
        }

        $appointment = Appointment::create($data);

        return response()->json(['success' => true, 'id' => $appointment->id, 'reference' => $appointment->reference]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Appointment::class);

        $appointment = Appointment::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->rules());

        if ($clash = $this->findClash($request, $appointment->id)) {
            return response()->json([
                'message' => 'This doctor already has an appointment at that time (' . $clash->reference . ').',
            ], 422);
        }

        $appointment->update($this->payload($request));

        return response()->json(['success' => true]);
    }

    /** Quick status change from the list (arrived, completed, cancelled...). */
    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Appointment::class);

        $request->validate(['status' => 'required|in:scheduled,confirmed,arrived,completed,cancelled,no_show']);

        $appointment = Appointment::whereNull('deleted_at')->findOrFail($id);
        $appointment->update(['status' => $request->status]);

        return response()->json(['success' => true, 'status' => $appointment->status]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Appointment::class);

        Appointment::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Appointment::class);

        $ids = (array) $request->selectedIds;
        Appointment::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /** Day view for the diary board: one doctor per column. */
    public function board(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Appointment::class);

        $date = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();
        $day = strtolower($date->format('D'));

        $doctors = Doctor::whereNull('deleted_at')->where('is_active', 1)
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->orderBy('name')->get();

        $appointments = Appointment::with('patient')->whereNull('deleted_at')
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('doctor_id', $doctors->pluck('id'))
            ->orderBy('scheduled_at')->get()->groupBy('doctor_id');

        return response()->json([
            'date' => $date->toDateString(),
            'columns' => $doctors->map(function ($doctor) use ($appointments, $day) {
                $hours = $doctor->availability_list[$day] ?? null;

                return [
                    'doctor_id' => $doctor->id,
                    'doctor_name' => $doctor->name,
                    'specialty' => $doctor->specialty,
                    'working' => is_array($hours) && count($hours) === 2,
                    'hours' => $hours,
                    'appointments' => $appointments->get($doctor->id, collect())
                        ->map(fn ($a) => $this->present($a))->values(),
                ];
            })->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * An overlapping, diary-holding appointment for the same doctor, or null.
     * Overlap is [start, start+duration) on both sides, so back-to-back slots
     * are fine but a one-minute overlap is not.
     */
    private function findClash(Request $request, $exceptId = null)
    {
        $start = Carbon::parse($request->scheduled_at);
        $end = $start->copy()->addMinutes((int) ($request->duration_minutes ?: 15));

        // Cancelled / completed / no-show slots have released their time.
        $status = $request->status ?: 'scheduled';
        if (! in_array($status, Appointment::blockingStatuses(), true)) {
            return null;
        }

        return Appointment::whereNull('deleted_at')
            ->where('doctor_id', $request->doctor_id)
            ->whereIn('status', Appointment::blockingStatuses())
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            // Same day first so the scan uses the scheduled_at index.
            ->whereDate('scheduled_at', $start->toDateString())
            ->get()
            ->first(function ($other) use ($start, $end) {
                $otherStart = Carbon::parse($other->scheduled_at);
                $otherEnd = $otherStart->copy()->addMinutes((int) ($other->duration_minutes ?: 15));

                return $start->lt($otherEnd) && $end->gt($otherStart);
            });
    }

    private function present(Appointment $a)
    {
        return [
            'id' => $a->id,
            'reference' => $a->reference,
            'patient_id' => $a->patient_id,
            'patient_name' => $a->patient ? $a->patient->name : '',
            'patient_mrn' => $a->patient ? $a->patient->mrn : '',
            'patient_phone' => $a->patient ? $a->patient->phone : '',
            'doctor_id' => $a->doctor_id,
            'doctor_name' => $a->doctor ? $a->doctor->name : '',
            'department_id' => $a->department_id,
            'department_name' => $a->department ? $a->department->name : null,
            'scheduled_at' => optional($a->scheduled_at)->toIso8601String(),
            'duration_minutes' => (int) $a->duration_minutes,
            'type' => $a->type,
            'status' => $a->status,
            'reason' => $a->reason,
            'fee' => (float) $a->fee,
            'notes' => $a->notes,
            'has_visit' => (bool) $a->visit,
        ];
    }

    private function rules()
    {
        return [
            'patient_id' => 'required|exists:patients,id',
            'doctor_id' => 'required|exists:doctors,id',
            'department_id' => 'nullable|exists:hospital_departments,id',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:5|max:480',
            'type' => 'required|in:consultation,follow_up,procedure,emergency,teleconsult',
            'status' => 'required|in:scheduled,confirmed,arrived,completed,cancelled,no_show',
            'fee' => 'nullable|numeric|min:0',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'patient_id' => $request->patient_id,
            'doctor_id' => $request->doctor_id,
            'department_id' => $request->department_id ?: null,
            'scheduled_at' => $request->scheduled_at,
            'duration_minutes' => $request->duration_minutes ?: 15,
            'type' => $request->type,
            'status' => $request->status,
            'reason' => $request->reason,
            'fee' => $request->filled('fee') ? $request->fee : 0,
            'notes' => $request->notes,
        ];
    }
}
