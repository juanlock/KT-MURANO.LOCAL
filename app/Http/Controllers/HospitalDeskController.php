<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Doctor;
use App\Models\ErCase;
use App\Models\HmsVisitor;
use App\Models\HospitalBed;
use App\Models\Patient;
use App\Support\HospitalShifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admission desk (user's rule, 2026-10-21): the hospital's single front door.
 * Every patient is registered here with a triage (initial assessment, level
 * 1-5) and sent to the WAITING ROOM, an ER BED (both an Emergency Room case)
 * or straight to a WARD BED (an admission). The waiting room is called from
 * here into a bed; visitors of patients in the hospital are checked in / out
 * here. hms_admission_desk (+ hms_visitors for the visitors).
 */
class HospitalDeskController extends Controller
{
    public function overview(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_admission_desk', 'hms_visitors'), 403);

        $waiting = ErCase::with('patient')->whereNull('deleted_at')->where('status', '!=', 'closed')->whereNull('bed_id')
            ->orderByRaw('COALESCE(triage_level, 9)')->orderBy('arrived_at')->get();
        $beds = HospitalBed::with('ward')->whereNull('deleted_at')->where('status', 'available')
            ->whereHas('ward', fn ($w) => $w->whereNull('deleted_at')->where('is_active', 1))->orderBy('ward_id')->orderBy('bed_number')->get();

        return response()->json([
            'waiting_room' => $waiting->map(fn ($c) => [
                'id' => $c->id, 'reference' => $c->reference, 'patient_id' => $c->patient_id,
                'name' => optional($c->patient)->name, 'mrn' => optional($c->patient)->mrn,
                'triage_level' => $c->triage_level, 'complaint' => $c->complaint, 'status' => $c->status,
                'arrived_at' => optional($c->arrived_at)->toIso8601String(),
                'wait_minutes' => $c->arrived_at ? (int) $c->arrived_at->diffInMinutes(now()) : null,
            ])->values(),
            'er_beds' => $beds->filter(fn ($b) => optional($b->ward)->type === 'emergency')->map(fn ($b) => ['id' => $b->id, 'label' => $b->bed_number.' · '.optional($b->ward)->name])->values(),
            'ward_beds' => $beds->filter(fn ($b) => optional($b->ward)->type !== 'emergency')->map(fn ($b) => [
                'id' => $b->id, 'label' => optional($b->ward)->name.' · '.$b->bed_number, 'rate' => $b->effective_rate,
            ])->values(),
            'doctors' => Doctor::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'specialty']),
            'census' => HospitalShifts::census(),
            'today' => [
                'arrivals' => ErCase::whereNull('deleted_at')->where('arrived_at', '>=', today())->count()
                    + Admission::whereNull('deleted_at')->where('admitted_at', '>=', today())->count(),
                'visitors_in' => HmsVisitor::whereNull('deleted_at')->whereNull('checked_out_at')->count(),
            ],
        ]);
    }

    /**
     * Register an arrival: the patient (registered or new) + triage, and the
     * destination -- waiting_room / er_bed (an Emergency Room case, through
     * HospitalErController so both screens share one rule set) or ward (an
     * admission in a free ward bed, the bed taken in the same transaction).
     */
    public function intake(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_admission_desk'), 403);
        $request->validate([
            'destination' => 'required|in:waiting_room,er_bed,ward',
            'bed_id' => ['nullable', 'required_unless:destination,waiting_room', \App\Support\CompanyExists::rule('hospital_beds')],
        ]);

        if ($request->destination !== 'ward') {
            $request->merge(['bed_id' => $request->destination === 'er_bed' ? $request->bed_id : null]);

            return app(HospitalErController::class)->store($request);
        }

        $request->validate([
            'patient_id' => ['nullable', 'required_without:new_patient.name', \App\Support\CompanyExists::rule('patients')],
            'new_patient.name' => 'nullable|required_without:patient_id|string|max:191',
            'doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
            'triage_level' => 'nullable|integer|between:1,5',
        ]);
        if ($request->filled('patient_id') && Admission::whereNull('deleted_at')->where('patient_id', $request->patient_id)->where('status', 'admitted')->exists()) {
            return response()->json(['message' => 'This patient is already admitted. Discharge or transfer them first.'], 422);
        }
        $userId = optional($request->user('api'))->id;
        try {
            $admission = DB::transaction(function () use ($request, $userId) {
                $patientId = $request->patient_id ?: $this->newPatient($request->input('new_patient'));
                $bed = HospitalBed::with('ward')->whereNull('deleted_at')->lockForUpdate()->find($request->bed_id);
                if (! $bed || $bed->status !== 'available' || optional($bed->ward)->type === 'emergency') {
                    throw new \RuntimeException('That bed has just been taken. Pick another one.');
                }
                $triage = $request->triage_level ? 'Triage '.$request->triage_level.'. ' : '';
                $a = Admission::create([
                    'reference' => Admission::nextReference('ADM'), 'patient_id' => $patientId, 'doctor_id' => $request->doctor_id ?: null,
                    'ward_id' => $bed->ward_id, 'bed_id' => $bed->id, 'department_id' => optional($bed->ward)->department_id,
                    'admitted_at' => now(), 'daily_rate' => $bed->effective_rate,
                    'reason' => trim($triage.($request->complaint ?? '')), 'diagnosis' => $request->diagnosis,
                    'status' => 'admitted', 'created_by' => $userId,
                ]);
                $bed->update(['status' => 'occupied']);

                return $a;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'admission_id' => $admission->id, 'reference' => $admission->reference, 'patient_id' => $admission->patient_id]);
    }

    /** Waiting room -> an ER bed (or a ward bed = admitted from the waiting room). */
    public function callIn(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_admission_desk', 'hms_er'), 403);
        $request->validate(['bed_id' => ['required', \App\Support\CompanyExists::rule('hospital_beds')]]);
        $case = ErCase::whereNull('deleted_at')->where('status', '!=', 'closed')->findOrFail($id);
        if ($case->bed_id) {
            return response()->json(['message' => 'This patient already has a bed.'], 422);
        }
        try {
            DB::transaction(function () use ($request, $case) {
                $bed = HospitalBed::with('ward')->whereNull('deleted_at')->lockForUpdate()->find($request->bed_id);
                if (! $bed || $bed->status !== 'available') {
                    throw new \RuntimeException('That bed has just been taken. Pick another one.');
                }
                if (optional($bed->ward)->type === 'emergency') {
                    $case->update(['bed_id' => $bed->id, 'location' => 'er_bed']);
                    $bed->update(['status' => 'occupied']);

                    return;
                }
                // A ward bed: the ER case closes as "admitted" and the admission takes the bed.
                $a = Admission::create([
                    'reference' => Admission::nextReference('ADM'), 'patient_id' => $case->patient_id, 'doctor_id' => $case->doctor_id,
                    'ward_id' => $bed->ward_id, 'bed_id' => $bed->id, 'department_id' => optional($bed->ward)->department_id,
                    'admitted_at' => now(), 'daily_rate' => $bed->effective_rate,
                    'reason' => trim('Triage '.($case->triage_level ?: '?').'. '.($case->complaint ?? '')), 'diagnosis' => $case->diagnosis,
                    'status' => 'admitted', 'created_by' => auth('api')->id(),
                ]);
                $bed->update(['status' => 'occupied']);
                $case->update(['status' => 'closed', 'disposition' => 'admitted', 'closed_at' => now(), 'admission_id' => $a->id,
                    'treatment_started_at' => $case->treatment_started_at ?: now()]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- visitors

    public function visitors(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_visitors', 'hms_admission_desk'), 403);
        $q = HmsVisitor::whereNull('deleted_at');
        if ($request->get('scope') !== 'all') {
            $q->where(fn ($w) => $w->whereNull('checked_out_at')->orWhere('checked_in_at', '>=', today()));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('name', 'LIKE', "%$s%")->orWhere('document', 'LIKE', "%$s%"));
        }
        $rows = $q->orderByRaw('checked_out_at IS NULL DESC')->orderByDesc('checked_in_at')->limit(200)->get();
        $patients = Patient::whereIn('id', $rows->pluck('patient_id'))->pluck('name', 'id');

        return response()->json(['visitors' => $rows->map(fn ($v) => [
            'id' => $v->id, 'name' => $v->name, 'document' => $v->document, 'relation' => $v->relation, 'phone' => $v->phone,
            'patient_id' => $v->patient_id, 'patient_name' => $patients[$v->patient_id] ?? '', 'badge' => $v->badge,
            'checked_in_at' => optional($v->checked_in_at)->toIso8601String(), 'checked_out_at' => optional($v->checked_out_at)->toIso8601String(),
            'notes' => $v->notes,
        ])->values()]);
    }

    /** Check a visitor in -- only to see a patient who is in the hospital now. */
    public function checkIn(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_visitors', 'hms_admission_desk'), 403);
        $request->validate([
            'patient_id' => ['required', \App\Support\CompanyExists::rule('patients')],
            'name' => 'required|string|max:191', 'document' => 'nullable|string|max:64',
            'relation' => 'nullable|string|max:64', 'phone' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:255',
        ]);
        if (! HospitalShifts::census()->contains('patient_id', (int) $request->patient_id)) {
            return response()->json(['message' => 'That patient is not in the hospital now.'], 422);
        }
        $count = HmsVisitor::whereDate('checked_in_at', today())->count() + 1;
        $v = HmsVisitor::create($request->only('patient_id', 'name', 'document', 'relation', 'phone', 'notes') + [
            'checked_in_at' => now(), 'badge' => 'V-'.now()->format('md').'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT),
            'created_by' => optional($request->user('api'))->id,
        ]);

        return response()->json(['success' => true, 'id' => $v->id, 'badge' => $v->badge]);
    }

    public function checkOut(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_visitors', 'hms_admission_desk'), 403);
        $v = HmsVisitor::whereNull('deleted_at')->whereNull('checked_out_at')->findOrFail($id);
        $v->update(['checked_out_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- printouts (2026-10-25)

    /** Where a patient is now: ward / room / bed / floor (open admission, else open ER case). */
    private function locationOf(int $patientId): array
    {
        $a = Admission::with('ward', 'bed.room.floor')->whereNull('deleted_at')->where('patient_id', $patientId)->where('status', 'admitted')->first();
        $bed = $a?->bed;
        $ref = $a?->reference;
        $area = 'ward';
        if (! $a) {
            $c = ErCase::with('bed.ward', 'bed.room.floor')->whereNull('deleted_at')->where('patient_id', $patientId)->where('status', '!=', 'closed')->first();
            $bed = $c?->bed;
            $ref = $c?->reference;
            $area = $c ? ($c->bed_id ? 'er_bed' : 'waiting_room') : null;
        }
        $room = $bed ? $bed->room : null;

        return [
            'area' => $area, 'reference' => $ref,
            'ward' => $bed ? optional($bed->ward)->name : optional($a?->ward)->name,
            'room' => $room ? $room->name : null,
            'bed' => $bed ? $bed->bed_number : null,
            'floor' => $room && $room->floor ? $room->floor->name : ($bed && $bed->ward ? $bed->ward->floor : null),
        ];
    }

    private function companyHeader(): array
    {
        $cid = (int) (Patient::actingCompanyId() ?? auth('api')->user()->company_id);
        $set = \App\Models\Setting::forCompany($cid);
        $logo = $set ? (string) $set->logo : '';

        return ['company' => DB::table('companies')->where('id', $cid)->value('name'),
            'logo' => $logo !== '' ? (str_contains($logo, '/') ? asset($logo) : asset('images/'.$logo)) : null];
    }

    /** The visitor pass: who visits, whom, where (room / floor), badge number. */
    public function visitorPass(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_visitors', 'hms_admission_desk'), 403);
        $v = HmsVisitor::whereNull('deleted_at')->findOrFail($id);
        $p = Patient::find($v->patient_id);

        return response()->json($this->companyHeader() + [
            'badge' => $v->badge, 'visitor' => $v->name, 'document' => $v->document, 'relation' => $v->relation,
            'patient' => optional($p)->name, 'checked_in_at' => optional($v->checked_in_at)->toIso8601String(),
        ] + $this->locationOf((int) $v->patient_id));
    }

    /** The patient's wristband: name, record number (barcode), birth date, allergies, blood group, where. */
    public function wristband(Request $request, $patientId)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_admission_desk', 'hms_patients_view', 'hms_er', 'hms_admissions'), 403);
        $p = Patient::whereNull('deleted_at')->findOrFail($patientId);

        return response()->json($this->companyHeader() + [
            'name' => $p->name, 'mrn' => $p->mrn, 'gender' => $p->gender, 'date_of_birth' => optional($p->date_of_birth)->toDateString() ?? $p->date_of_birth,
            'blood_group' => $p->blood_group, 'allergies' => $p->allergies, 'national_id' => $p->national_id,
        ] + $this->locationOf((int) $p->id));
    }

    private function newPatient(?array $np): int
    {
        return Patient::create([
            'mrn' => Patient::generateMrn(), 'name' => $np['name'], 'gender' => $np['gender'] ?? 'other',
            'date_of_birth' => $np['date_of_birth'] ?? null, 'phone' => $np['phone'] ?? null, 'email' => $np['email'] ?? null,
            'national_id' => $np['national_id'] ?? null, 'notes' => 'Registered at the admission desk', 'status' => 'active',
        ])->id;
    }
}
