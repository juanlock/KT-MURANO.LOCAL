<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Doctor;
use App\Models\ErCase;
use App\Models\HospitalBed;
use App\Models\Patient;
use App\Models\PatientVisit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Emergency Room (2026-10-21): the whole ER on one screen -- intake (existing
 * or quick new patient), triage 1-5 + vitals, doctor and ER bed, the board
 * (waiting -> in treatment -> observation) and the disposition (discharged,
 * admitted = an Admission in a ward bed, transferred, left without being seen,
 * deceased). Bed changes run in a transaction with the bed row locked, like
 * HospitalAdmissionController: an ER bed is "occupied" while a case holds it.
 * Permission hms_er only (the screen brings its own doctors / beds / patient
 * search, so an ER role needs nothing else).
 */
class HospitalErController extends Controller
{
    public function board(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ErCase::class);

        $since = now()->subHours(24);
        $cases = ErCase::with('patient', 'doctor', 'bed.ward', 'admission.bed.ward')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('status', '!=', 'closed')->orWhere('closed_at', '>=', $since))
            ->orderBy('triage_level')->orderBy('arrived_at')->get();

        $today = now()->startOfDay();
        $treatedToday = ErCase::whereNull('deleted_at')->where('arrived_at', '>=', $today)->whereNotNull('treatment_started_at')
            ->get(['arrived_at', 'treatment_started_at']);
        $avgWait = $treatedToday->count()
            ? (int) round($treatedToday->avg(fn ($c) => max(0, $c->arrived_at->diffInMinutes($c->treatment_started_at))))
            : null;
        $erBeds = $this->erBeds();

        return response()->json([
            'cases' => $cases->map(fn ($c) => $this->present($c))->values(),
            'kpis' => [
                'waiting' => $cases->where('status', 'waiting')->count(),
                'in_treatment' => $cases->where('status', 'in_treatment')->count(),
                'observation' => $cases->where('status', 'observation')->count(),
                'arrivals_today' => ErCase::whereNull('deleted_at')->where('arrived_at', '>=', $today)->count(),
                'closed_today' => ErCase::whereNull('deleted_at')->where('closed_at', '>=', $today)->count(),
                'avg_wait_minutes' => $avgWait,
                'beds_free' => $erBeds->where('status', 'available')->count(),
                'beds_total' => $erBeds->count(),
            ],
            'doctors' => Doctor::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')
                ->get(['id', 'name', 'specialty'])->values(),
            'er_beds' => $erBeds->map(fn ($b) => [
                'id' => $b->id, 'bed_number' => $b->bed_number, 'ward_name' => optional($b->ward)->name, 'status' => $b->status,
            ])->values(),
            'admit_beds' => HospitalBed::with('ward')->whereNull('deleted_at')->where('status', 'available')
                ->whereHas('ward', fn ($w) => $w->whereNull('deleted_at')->where('type', '!=', 'emergency'))
                ->orderBy('ward_id')->orderBy('bed_number')->get()
                ->map(fn ($b) => [
                    'id' => $b->id, 'bed_number' => $b->bed_number, 'ward_name' => optional($b->ward)->name,
                    'ward_type' => optional($b->ward)->type, 'rate' => $b->effective_rate,
                ])->values(),
        ]);
    }

    /** Closed cases, newest first (the History tab). */
    public function history(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ErCase::class);

        $perPage = max(1, min(100, (int) ($request->limit ?? 20)));
        $page = max(1, (int) $request->get('page', 1));
        $q = ErCase::with('patient', 'doctor', 'bed.ward', 'admission.bed.ward')->whereNull('deleted_at')->where('status', 'closed');
        if ($request->filled('start_date')) {
            $q->whereDate('arrived_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $q->whereDate('arrived_at', '<=', $request->end_date);
        }
        if ($request->filled('disposition')) {
            $q->where('disposition', $request->disposition);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('reference', 'LIKE', "%{$s}%")->orWhere('complaint', 'LIKE', "%{$s}%")
                ->orWhereHas('patient', fn ($p) => $p->where('name', 'LIKE', "%{$s}%")->orWhere('mrn', 'LIKE', "%{$s}%")));
        }
        $total = $q->count();
        $rows = $q->orderBy('arrived_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json(['totalRows' => $total, 'cases' => $rows->map(fn ($c) => $this->present($c))->values()]);
    }

    public function searchPatients(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ErCase::class);

        $search = trim((string) $request->get('search', ''));
        $patients = Patient::whereNull('deleted_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'LIKE', "%{$search}%")
                ->orWhere('mrn', 'LIKE', "%{$search}%")->orWhere('phone', 'LIKE', "%{$search}%")))
            ->orderBy('name')->limit(20)->get();

        return response()->json(['patients' => $patients->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'mrn' => $p->mrn, 'age' => $p->age, 'gender' => $p->gender,
            'allergies' => $p->allergies, 'blood_group' => $p->blood_group,
        ])->values()]);
    }

    /** Intake: the patient (existing or new), triage, vitals and, optionally, doctor + ER bed. */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', ErCase::class);

        $request->validate(array_merge($this->rules(), [
            'patient_id' => ['nullable', 'required_without:new_patient.name', \App\Support\CompanyExists::rule('patients')],
            'new_patient.name' => 'nullable|required_without:patient_id|string|max:191',
            'new_patient.gender' => 'nullable|in:male,female,other',
            'new_patient.date_of_birth' => 'nullable|date',
            'new_patient.phone' => 'nullable|string|max:40',
        ]));

        if ($request->filled('patient_id')) {
            $open = ErCase::whereNull('deleted_at')->where('patient_id', $request->patient_id)->where('status', '!=', 'closed')->first();
            if ($open) {
                return response()->json(['message' => 'This patient is already in the ER ('.$open->reference.').'], 422);
            }
        }

        $userId = optional($request->user('api'))->id;
        try {
            $case = DB::transaction(function () use ($request, $userId) {
                $patientId = $request->patient_id;
                if (! $patientId) {
                    $np = $request->input('new_patient');
                    $patientId = Patient::create([
                        'mrn' => Patient::generateMrn(),
                        'name' => $np['name'],
                        'gender' => $np['gender'] ?? 'other',
                        'date_of_birth' => $np['date_of_birth'] ?? null,
                        'phone' => $np['phone'] ?? null,
                        'notes' => 'Registered in the Emergency Room',
                        'status' => 'active',
                    ])->id;
                }
                $bed = $this->lockErBed($request->bed_id);

                $data = $this->payload($request);
                $data['patient_id'] = $patientId;
                $data['reference'] = ErCase::nextReference('ER');
                $data['arrived_at'] = $request->arrived_at ?: now();
                $data['status'] = 'waiting';
                $data['location'] = $bed ? 'er_bed' : 'waiting_room';
                $data['created_by'] = $userId;
                $case = ErCase::create($data);
                if ($bed) {
                    $bed->update(['status' => 'occupied']);
                }

                return $case;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'id' => $case->id, 'reference' => $case->reference]);
    }

    /** Triage / vitals / doctor / notes, and moving to another ER bed (the old one is freed). */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ErCase::class);
        $request->validate($this->rules());

        $case = ErCase::whereNull('deleted_at')->findOrFail($id);
        if ($case->status === 'closed') {
            return response()->json(['message' => 'This case is already closed.'], 422);
        }

        try {
            DB::transaction(function () use ($request, $case) {
                $data = $this->payload($request);
                $newBed = $request->bed_id ? (int) $request->bed_id : null;
                $data['location'] = $newBed ? 'er_bed' : 'waiting_room';
                if ($newBed !== $case->bed_id) {
                    $bed = $this->lockErBed($newBed);
                    $this->freeBed($case->bed_id);
                    if ($bed) {
                        $bed->update(['status' => 'occupied']);
                    }
                }
                $case->update($data);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    /** waiting -> in_treatment -> observation (and back); the first "in treatment" stamps the wait time. */
    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ErCase::class);
        $request->validate(['status' => 'required|in:waiting,in_treatment,observation']);

        $case = ErCase::whereNull('deleted_at')->findOrFail($id);
        if ($case->status === 'closed') {
            return response()->json(['message' => 'This case is already closed.'], 422);
        }
        $data = ['status' => $request->status];
        if ($request->status !== 'waiting' && ! $case->treatment_started_at) {
            $data['treatment_started_at'] = now();
        }
        $case->update($data);

        return response()->json(['success' => true]);
    }

    /**
     * Disposition. One transaction: the case closed, its ER bed freed, an
     * emergency visit on the patient's record (vitals, diagnosis), and for
     * "admitted" a new Admission in the chosen ward bed (that bed occupied).
     */
    public function close(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ErCase::class);
        $request->validate([
            'disposition' => ['required', Rule::in(ErCase::DISPOSITIONS)],
            'diagnosis' => 'nullable|string',
            'disposition_notes' => 'nullable|string',
            'admit_bed_id' => ['nullable', 'required_if:disposition,admitted', \App\Support\CompanyExists::rule('hospital_beds')],
            'doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
        ]);

        $case = ErCase::whereNull('deleted_at')->findOrFail($id);
        if ($case->status === 'closed') {
            return response()->json(['message' => 'This case is already closed.'], 422);
        }
        if ($request->disposition === 'admitted') {
            $already = Admission::whereNull('deleted_at')->where('patient_id', $case->patient_id)->where('status', 'admitted')->first();
            if ($already) {
                return response()->json(['message' => 'This patient is already admitted ('.$already->reference.').'], 422);
            }
        }

        $userId = optional($request->user('api'))->id;
        try {
            DB::transaction(function () use ($request, $case, $userId) {
                $doctorId = $request->doctor_id ?: $case->doctor_id;
                $diagnosis = $request->filled('diagnosis') ? $request->diagnosis : $case->diagnosis;
                $admissionId = null;

                if ($request->disposition === 'admitted') {
                    $bed = HospitalBed::with('ward')->whereNull('deleted_at')->lockForUpdate()->find($request->admit_bed_id);
                    if (! $bed || $bed->status !== 'available') {
                        throw new \RuntimeException('That bed has just been taken. Pick another one.');
                    }
                    $admissionId = Admission::create([
                        'reference' => Admission::nextReference('ADM'),
                        'patient_id' => $case->patient_id,
                        'doctor_id' => $doctorId,
                        'ward_id' => $bed->ward_id,
                        'bed_id' => $bed->id,
                        'department_id' => optional($bed->ward)->department_id,
                        'admitted_at' => now(),
                        'daily_rate' => $bed->effective_rate,
                        'reason' => trim(($case->complaint ?: '').' (ER '.$case->reference.')'),
                        'diagnosis' => $diagnosis,
                        'status' => 'admitted',
                        'created_by' => $userId,
                    ])->id;
                    $bed->update(['status' => 'occupied']);
                }

                if ($request->disposition !== 'lwbs') {
                    PatientVisit::create([
                        'reference' => PatientVisit::nextReference('VIS'),
                        'patient_id' => $case->patient_id,
                        'doctor_id' => $doctorId,
                        'visit_date' => $case->arrived_at,
                        'type' => 'emergency',
                        'temperature' => $case->temperature, 'pulse' => $case->pulse,
                        'bp_systolic' => $case->bp_systolic, 'bp_diastolic' => $case->bp_diastolic,
                        'respiratory_rate' => $case->respiratory_rate, 'spo2' => $case->spo2,
                        'complaint' => $case->complaint,
                        'diagnosis' => $diagnosis,
                        'treatment_plan' => $request->disposition_notes ?: $case->notes,
                        'status' => 'completed',
                        'fee' => 0,
                        'created_by' => $userId,
                    ]);
                }

                $this->freeBed($case->bed_id);
                $case->update([
                    'status' => 'closed',
                    'disposition' => $request->disposition,
                    'closed_at' => now(),
                    'disposition_notes' => $request->disposition_notes,
                    'diagnosis' => $diagnosis,
                    'doctor_id' => $doctorId,
                    'admission_id' => $admissionId,
                    'treatment_started_at' => $case->treatment_started_at ?: ($request->disposition === 'lwbs' ? null : now()),
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ErCase::class);

        $case = ErCase::whereNull('deleted_at')->findOrFail($id);
        DB::transaction(function () use ($case) {
            if ($case->status !== 'closed') {
                $this->freeBed($case->bed_id);
            }
            $case->delete();
        });

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    /** The acting company's ER beds (beds of wards of type "emergency"). */
    private function erBeds()
    {
        return HospitalBed::with('ward')->whereNull('deleted_at')
            ->whereHas('ward', fn ($w) => $w->whereNull('deleted_at')->where('type', 'emergency'))
            ->orderBy('bed_number')->get();
    }

    private function lockErBed($bedId)
    {
        if (! $bedId) {
            return null;
        }
        $bed = HospitalBed::with('ward')->whereNull('deleted_at')->lockForUpdate()->find($bedId);
        if (! $bed || optional($bed->ward)->type !== 'emergency') {
            throw new \RuntimeException('That is not an ER bed.');
        }
        if ($bed->status !== 'available') {
            throw new \RuntimeException('That bed has just been taken. Pick another one.');
        }

        return $bed;
    }

    /** The patient left the ER bed: it waits for cleaning (HospitalBed::release). */
    private function freeBed($bedId): void
    {
        HospitalBed::release($bedId);
    }

    private function rules(): array
    {
        return [
            'doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
            'bed_id' => ['nullable', \App\Support\CompanyExists::rule('hospital_beds')],
            'arrived_at' => 'nullable|date',
            'arrival_mode' => ['nullable', Rule::in(ErCase::ARRIVAL_MODES)],
            'triage_level' => 'nullable|integer|between:1,5',
            'complaint' => 'nullable|string',
            'temperature' => 'nullable|numeric|between:25,45',
            'pulse' => 'nullable|integer|between:0,300',
            'bp_systolic' => 'nullable|integer|between:0,300',
            'bp_diastolic' => 'nullable|integer|between:0,250',
            'respiratory_rate' => 'nullable|integer|between:0,80',
            'spo2' => 'nullable|integer|between:0,100',
            'pain_score' => 'nullable|integer|between:0,10',
            'diagnosis' => 'nullable|string',
            'notes' => 'nullable|string',
        ];
    }

    private function payload(Request $request): array
    {
        $data = [];
        foreach (['arrival_mode', 'triage_level', 'complaint', 'temperature', 'pulse', 'bp_systolic', 'bp_diastolic',
            'respiratory_rate', 'spo2', 'pain_score', 'diagnosis', 'notes'] as $f) {
            $data[$f] = $request->filled($f) ? $request->input($f) : null;
        }
        $data['arrival_mode'] = $data['arrival_mode'] ?: 'walk_in';
        $data['doctor_id'] = $request->doctor_id ?: null;
        $data['bed_id'] = $request->bed_id ?: null;
        if ($request->filled('arrived_at')) {
            $data['arrived_at'] = $request->arrived_at;
        }

        return $data;
    }

    private function present(ErCase $c): array
    {
        $end = $c->treatment_started_at ?: ($c->closed_at ?: now());

        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'patient_id' => $c->patient_id,
            'patient_name' => optional($c->patient)->name,
            'patient_mrn' => optional($c->patient)->mrn,
            'patient_age' => optional($c->patient)->age,
            'patient_gender' => optional($c->patient)->gender,
            'allergies' => optional($c->patient)->allergies,
            'doctor_id' => $c->doctor_id,
            'doctor_name' => optional($c->doctor)->name,
            'bed_id' => $c->bed_id,
            'bed_number' => optional($c->bed)->bed_number,
            'location' => $c->location ?: ($c->bed_id ? 'er_bed' : 'waiting_room'),
            'arrived_at' => optional($c->arrived_at)->toIso8601String(),
            'arrival_mode' => $c->arrival_mode,
            'triage_level' => $c->triage_level,
            'complaint' => $c->complaint,
            'temperature' => $c->temperature !== null ? (float) $c->temperature : null,
            'pulse' => $c->pulse, 'bp_systolic' => $c->bp_systolic, 'bp_diastolic' => $c->bp_diastolic,
            'respiratory_rate' => $c->respiratory_rate, 'spo2' => $c->spo2, 'pain_score' => $c->pain_score,
            'status' => $c->status,
            'treatment_started_at' => optional($c->treatment_started_at)->toIso8601String(),
            'wait_minutes' => $c->arrived_at ? max(0, (int) $c->arrived_at->diffInMinutes($end)) : null,
            'minutes_in_er' => $c->arrived_at ? max(0, (int) $c->arrived_at->diffInMinutes($c->closed_at ?: now())) : null,
            'diagnosis' => $c->diagnosis,
            'notes' => $c->notes,
            'disposition' => $c->disposition,
            'closed_at' => optional($c->closed_at)->toIso8601String(),
            'disposition_notes' => $c->disposition_notes,
            'admission_reference' => optional($c->admission)->reference,
            'admission_bed' => $c->admission && $c->admission->bed
                ? trim((optional($c->admission->bed->ward)->name ?? '').' · '.$c->admission->bed->bed_number, ' ·') : null,
        ];
    }
}
