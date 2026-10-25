<?php

namespace App\Http\Controllers;

use App\Models\HmsMedicationDose;
use App\Models\HmsShiftPatient;
use App\Models\HmsStaffShift;
use App\Support\HospitalShifts;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * My round (user's request, 2026-10-25): the doctor's / nurse's patients of
 * the shift as cards on the floor maps, numbered in the order to visit them
 * (floor, then room, then bed), each with what matters today: who, what they
 * suffer from, the medication given / still due / not given, care orders due,
 * last vital signs and recent lab results. A card opens the visit: the doctor
 * writes the progress or a prescription, the nurse records doses, vitals or a
 * change seen (nursing note) -- through the existing clinical endpoints.
 *
 *  GET  hospital/rounds[?all=1]                 my patients (all = every patient, for supervisors)
 *  POST hospital/admissions/{id}/nursing-note   {text}  a change seen by nursing
 */
class HospitalRoundsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless(HospitalShifts::can($user, 'hms_my_patients', 'hms_shifts', 'hms_treatments', 'hms_admissions'), 403);
        HmsMedicationDose::markMissed();
        [$employeeId, $doctorIds] = HospitalShifts::staffOf($user);
        $isDoctor = (bool) $doctorIds || (HospitalShifts::can($user, 'hms_treatments') && ! $employeeId);
        $canAll = HospitalShifts::can($user, 'hms_shifts') || in_array($user->type, ['admin', 'SuperAdmin'], true);

        // My shift rows running now and the patients on them.
        $mine = collect();
        foreach (HospitalShifts::current() as [$date, $shift]) {
            $mine = $mine->merge(HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', $date)->where('shift', $shift)
                ->where(fn ($w) => $w->when($employeeId, fn ($x) => $x->orWhere('employee_id', $employeeId))
                    ->when($doctorIds, fn ($x) => $x->orWhereIn('doctor_id', $doctorIds))
                    ->when(! $employeeId && ! $doctorIds, fn ($x) => $x->whereRaw('1 = 0')))->get());
        }
        $assigned = HmsShiftPatient::whereIn('staff_shift_id', $mine->pluck('id'))->get()->keyBy('patient_id');
        $census = HospitalShifts::census()->keyBy('patient_id');
        $ids = $assigned->keys();
        // A doctor also sees the inpatients they attend.
        if ($doctorIds) {
            $ids = $ids->merge(DB::table('admissions')->whereIn('doctor_id', $doctorIds)->where('status', 'admitted')->whereNull('deleted_at')->pluck('patient_id'));
        }
        $all = $request->boolean('all') && $canAll;
        // Supervisors / admins with nobody assigned see the whole hospital.
        if ($all || ($ids->isEmpty() && $canAll)) {
            $all = true;
            $ids = $census->keys();
        }
        $ids = $ids->unique()->filter(fn ($id) => $census->has($id))->values();

        $floors = app(HospitalSetupController::class)->mapFloors(true);
        $bedOf = [];   // patient_id => [floor index, bed]
        foreach ($floors as $fi => $f) {
            foreach ($f['beds'] as $b) {
                if (! empty($b['patient_id'])) {
                    $bedOf[$b['patient_id']] = [$fi, $b, collect($f['rooms'])->firstWhere('id', $b['room_id'])];
                }
            }
        }

        $patients = $ids->map(fn ($pid) => $this->card($pid, $census[$pid], $assigned[$pid] ?? null, $bedOf[$pid] ?? null, $floors))->all();
        // The route: floor by floor, then room (top-left first), then bed; patients without a bed on a map last.
        usort($patients, fn ($a, $b) => [$a['floor_order'], $a['room_y'], $a['room_x'], $a['bed_y'], $a['bed_x'], $a['name']]
            <=> [$b['floor_order'], $b['room_y'], $b['room_x'], $b['bed_y'], $b['bed_x'], $b['name']]);
        foreach ($patients as $i => &$p) {
            $p['route_no'] = $i + 1;
        }
        unset($p);
        $usedFloors = collect($patients)->pluck('floor_id')->filter()->unique()->all();

        return response()->json([
            'mode' => $isDoctor ? 'doctor' : 'nurse',
            'all' => $all,
            'can_all' => $canAll,
            'can_prescribe' => HospitalShifts::can($user, 'hms_treatments'),
            'shifts' => $mine->map(fn ($r) => ['shift' => $r->shift, 'position' => $r->position, 'name' => optional(HospitalShifts::types()->get($r->shift))['name'] ?? $r->shift])->values(),
            'floors' => collect($floors)->filter(fn ($f) => in_array($f['id'], $usedFloors, true))->values(),
            'patients' => $patients,
            'routes' => HospitalTreatmentController::ROUTES,
        ]);
    }

    private function card(int $pid, array $c, $assignment, ?array $bed, array $floors): array
    {
        $p = DB::table('patients')->where('id', $pid)->first(['name', 'mrn', 'gender', 'date_of_birth', 'allergies', 'blood_group', 'chronic_conditions']);
        $adm = $c['admission_id'] ? DB::table('admissions')->where('id', $c['admission_id'])->first() : null;
        $er = $c['er_case_id'] ? DB::table('er_cases')->where('id', $c['er_case_id'])->first() : null;
        $today = [now()->startOfDay(), now()->endOfDay()];

        $doses = DB::table('hms_medication_doses as d')->join('hms_medication_orders as o', 'o.id', '=', 'd.order_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.given_by')
            ->where('d.patient_id', $pid)->whereBetween('d.scheduled_at', $today)->orderBy('d.scheduled_at')
            ->get(['d.id', 'd.scheduled_at', 'd.status', 'd.given_at', 'd.dispensed', 'o.medicine', 'o.dose', 'o.route', 'u.username as by']);
        $yesterdayMissed = DB::table('hms_medication_doses')->where('patient_id', $pid)->where('status', 'missed')
            ->where('scheduled_at', '>=', now()->subDay()->startOfDay())->where('scheduled_at', '<', now()->startOfDay())->count();

        $care = $adm ? DB::table('hms_care_orders')->where('admission_id', $adm->id)->where('status', 'active')->get() : collect();
        $lastCare = $care->isEmpty() ? collect() : DB::table('hms_care_logs')->whereIn('care_order_id', $care->pluck('id'))
            ->selectRaw('care_order_id, MAX(done_at) last')->groupBy('care_order_id')->pluck('last', 'care_order_id');
        $vitals = $adm ? DB::table('hms_vitals')->where('admission_id', $adm->id)->orderByDesc('taken_at')->first() : null;
        $labs = DB::table('lab_order_items as i')->join('lab_orders as o', 'o.id', '=', 'i.lab_order_id')
            ->where('o.patient_id', $pid)->whereNull('o.deleted_at')->where('o.ordered_at', '>=', now()->subDays(3))
            ->orderByDesc('o.ordered_at')->limit(8)
            ->get(['o.ordered_at', 'o.status', 'i.test_name', 'i.result_value', 'i.unit', 'i.normal_range', 'i.flag']);
        $notes = $adm ? DB::table('hms_progress_notes')->where('admission_id', $adm->id)->whereNull('deleted_at')
            ->where('noted_at', '>=', now()->startOfDay())->orderByDesc('noted_at')->get(['kind', 'subjective', 'assessment', 'plan', 'noted_at']) : collect();
        $treatmentId = $c['treatment_id'] ?? null;

        [$fi, $b, $room] = $bed ?? [null, null, null];
        $floor = $fi !== null ? $floors[$fi] : null;

        return [
            'patient_id' => $pid, 'name' => $p->name ?? $c['name'], 'mrn' => $p->mrn ?? null,
            'age' => ! empty($p->date_of_birth) ? Carbon::parse($p->date_of_birth)->age : null, 'gender' => $p->gender ?? null,
            'allergies' => $p->allergies ?? null, 'blood_group' => $p->blood_group ?? null, 'chronic' => $p->chronic_conditions ?? null,
            'area' => $c['area'], 'where' => $c['where'],
            'admission_id' => $c['admission_id'], 'er_case_id' => $c['er_case_id'], 'treatment_id' => $treatmentId,
            'suffers' => ($adm->diagnosis ?? null) ?: (($adm->reason ?? null) ?: (($er->diagnosis ?? null) ?: ($er->complaint ?? null))),
            'triage' => $c['triage_level'] ?? null,
            'day' => $adm ? (int) Carbon::parse($adm->admitted_at)->startOfDay()->diffInDays(now()->startOfDay()) + 1 : null,
            'assignment_id' => optional($assignment)->id,
            'visited_at' => $assignment && $assignment->visited_at ? $assignment->visited_at->toIso8601String() : null,
            'doses' => $doses->map(fn ($d) => (array) $d + ['late' => $d->status === 'pending' && $d->scheduled_at < now()])->values(),
            'doses_count' => [
                'given' => $doses->where('status', 'given')->count(), 'pending' => $doses->where('status', 'pending')->count(),
                'late' => $doses->where('status', 'pending')->where('scheduled_at', '<', now())->count(),
                'not_given' => $doses->whereIn('status', ['omitted', 'refused', 'missed'])->count(), 'missed_yesterday' => $yesterdayMissed,
            ],
            'care_due' => $care->map(fn ($o) => [
                'id' => $o->id, 'kind' => $o->kind, 'description' => $o->description, 'every_hours' => $o->every_hours,
                'last' => $lastCare[$o->id] ?? null,
                'due' => $o->every_hours ? (! isset($lastCare[$o->id]) || Carbon::parse($lastCare[$o->id])->diffInMinutes(now()) >= $o->every_hours * 60) : false,
            ])->values(),
            'vitals' => $vitals,
            'labs' => $labs,
            'notes_today' => $notes,
            // Map position.
            'floor_id' => $floor['id'] ?? null, 'floor_name' => $floor['name'] ?? null, 'bed_id' => $b['id'] ?? null, 'bed' => $b['bed_number'] ?? null,
            'room' => $room['name'] ?? null, 'x' => $b['x'] ?? null, 'y' => $b['y'] ?? null,
            'floor_order' => $fi ?? 999, 'room_y' => $room['y'] ?? 999, 'room_x' => $room['x'] ?? 999, 'bed_y' => $b['y'] ?? 999, 'bed_x' => $b['x'] ?? 999,
        ];
    }

    /** A change the nurse saw on the round (shown in the chart's Progress, marked as nursing). */
    public function nursingNote(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_my_patients', 'hms_treatments_administer', 'hms_treatments', 'hms_shifts'), 403);
        $v = $request->validate(['text' => 'required|string|max:4000']);
        $a = \App\Models\Admission::whereNull('deleted_at')->findOrFail($id);
        DB::table('hms_progress_notes')->insert([
            'company_id' => $a->company_id, 'admission_id' => $a->id, 'patient_id' => $a->patient_id, 'kind' => 'nursing',
            'objective' => $v['text'], 'user_id' => $request->user('api')->id, 'noted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }
}
