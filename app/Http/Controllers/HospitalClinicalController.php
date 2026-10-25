<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\ErCase;
use App\Models\HospitalBed;
use App\Support\HospitalShifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The clinical stay (user's analysis, 2026-10-25, phase 3).
 *
 *  GET  hospital/admissions/{id}/chart       everything of the stay: notes, vitals, care orders (kardex),
 *                                            medication doses, lab results, consults, discharge plan
 *  POST hospital/admissions/{id}/notes       daily progress (SOAP)                 doctors
 *  POST hospital/admissions/{id}/vitals      vital-sign round                      nursing
 *  POST hospital/admissions/{id}/care-orders diet / care / activity order          doctors
 *  POST hospital/care-orders/{id}/stop|done  stop it / done now (kardex)
 *  POST hospital/admissions/{id}/consults    interconsultation (alert to the specialist)
 *  GET  hospital/consults?mine=1             the consults for the user's doctor(s)
 *  POST hospital/consults/{id}/answer        accept / answer / cancel
 *  PUT  hospital/admissions/{id}/discharge-plan {planned_at, items}  checklist 24-48 h before
 *  GET  hospital/admissions/{id}/epicrisis   the discharge summary drafted from the stay
 *  PUT  hospital/admissions/{id}/epicrisis   saved (admissions.discharge_summary)
 *  GET  hospital/control-tower               bed status, alerts, suggestion of a bed
 */
class HospitalClinicalController extends Controller
{
    public const PLAN_ITEMS = [
        'education' => 'Patient and family taught the home care',
        'prescriptions' => 'Discharge prescriptions ready',
        'devices' => 'Devices arranged (crutches, oxygen...)',
        'followup' => 'Follow-up appointment booked',
        'transport' => 'Transport home arranged',
        'account' => 'Account closed (billing)',
        'family' => 'Family told the time of discharge',
    ];

    private function canView(Request $r): void
    {
        abort_unless(HospitalShifts::can($r->user('api'), 'hms_my_patients', 'hms_admissions', 'hms_treatments', 'hms_treatments_administer', 'hms_shifts'), 403);
    }

    private function canDoctor(Request $r): void
    {
        abort_unless(HospitalShifts::can($r->user('api'), 'hms_treatments', 'hms_admissions'), 403, 'Only doctors write progress notes and orders.');
    }

    private function canNurse(Request $r): void
    {
        abort_unless(HospitalShifts::can($r->user('api'), 'hms_treatments_administer', 'hms_my_patients', 'hms_treatments'), 403);
    }

    private function stay($id): Admission
    {
        return Admission::with('patient', 'ward', 'bed', 'doctor')->whereNull('deleted_at')->findOrFail($id);
    }

    private function userName($id): ?string
    {
        return $id ? DB::table('users')->where('id', $id)->value(DB::raw("TRIM(CONCAT(COALESCE(firstname,''),' ',COALESCE(lastname,'')))")) : null;
    }

    public function chart(Request $request, $id)
    {
        $this->canView($request);
        $a = $this->stay($id);
        $cid = $a->company_id;
        $doctors = DB::table('doctors')->where('company_id', $cid)->whereNull('deleted_at')->pluck('name', 'id');
        $users = fn ($ids) => DB::table('users')->whereIn('id', collect($ids)->filter()->unique())->get(['id', 'firstname', 'lastname'])
            ->mapWithKeys(fn ($u) => [$u->id => trim($u->firstname.' '.$u->lastname)]);

        $notes = DB::table('hms_progress_notes')->where('admission_id', $a->id)->whereNull('deleted_at')->orderByDesc('noted_at')->get();
        $vitals = DB::table('hms_vitals')->where('admission_id', $a->id)->orderByDesc('taken_at')->limit(60)->get();
        $orders = DB::table('hms_care_orders')->where('admission_id', $a->id)->orderBy('status')->orderByDesc('starts_at')->get();
        $logs = DB::table('hms_care_logs')->whereIn('care_order_id', $orders->pluck('id'))->orderByDesc('done_at')->get()->groupBy('care_order_id');
        $treatments = DB::table('hms_treatments')->where('admission_id', $a->id)->pluck('id');
        $doses = DB::table('hms_medication_doses as d')->join('hms_medication_orders as o', 'o.id', '=', 'd.order_id')
            ->whereIn('d.treatment_id', $treatments)->whereBetween('d.scheduled_at', [now()->subHours(24), now()->addHours(24)])
            ->orderBy('d.scheduled_at')->get(['d.id', 'd.scheduled_at', 'd.status', 'd.given_at', 'd.given_by', 'd.dispensed', 'o.medicine', 'o.dose', 'o.route']);
        $labs = DB::table('lab_order_items as i')->join('lab_orders as o', 'o.id', '=', 'i.lab_order_id')
            ->where('o.patient_id', $a->patient_id)->whereNull('o.deleted_at')->where('o.ordered_at', '>=', $a->admitted_at)
            ->orderByDesc('o.ordered_at')->get(['o.reference', 'o.ordered_at', 'o.status', 'o.priority', 'i.test_name', 'i.result_value', 'i.unit', 'i.normal_range', 'i.flag']);
        $consults = DB::table('hms_consults')->where('admission_id', $a->id)->orderByDesc('id')->get();
        $plan = DB::table('hms_discharge_plans')->where('admission_id', $a->id)->first();
        $names = $users(array_merge($notes->pluck('user_id')->all(), $vitals->pluck('user_id')->all(), $doses->pluck('given_by')->all(), $logs->flatten()->pluck('user_id')->all()));

        return response()->json([
            'admission' => [
                'id' => $a->id, 'reference' => $a->reference, 'status' => $a->status, 'patient_id' => $a->patient_id,
                'patient_name' => optional($a->patient)->name, 'mrn' => optional($a->patient)->mrn, 'allergies' => optional($a->patient)->allergies,
                'blood_group' => optional($a->patient)->blood_group, 'ward' => optional($a->ward)->name, 'bed' => optional($a->bed)->bed_number,
                'doctor' => optional($a->doctor)->name, 'admitted_at' => optional($a->admitted_at)->toIso8601String(),
                'discharged_at' => optional($a->discharged_at)->toIso8601String(), 'left_at' => optional($a->left_at)->toIso8601String(),
                'reason' => $a->reason, 'diagnosis' => $a->diagnosis, 'nights' => $a->nights, 'discharge_summary' => $a->discharge_summary,
            ],
            'notes' => $notes->map(fn ($n) => (array) $n + ['doctor' => $doctors[$n->doctor_id] ?? null, 'by' => $names[$n->user_id] ?? null])->values(),
            'vitals' => $vitals->map(fn ($v) => (array) $v + ['by' => $names[$v->user_id] ?? null])->values(),
            'care_orders' => $orders->map(function ($o) use ($logs, $names) {
                $last = optional($logs->get($o->id))->first();
                $due = $o->status === 'active' && $o->every_hours ? (! $last || now()->diffInMinutes($last->done_at) >= $o->every_hours * 60) : false;

                return (array) $o + ['last_done' => $last ? $last->done_at : null, 'last_by' => $last ? ($names[$last->user_id] ?? null) : null,
                    'done_count' => optional($logs->get($o->id))->count() ?? 0, 'due' => $due];
            })->values(),
            'doses' => $doses->map(fn ($d) => (array) $d + ['by' => $names[$d->given_by] ?? null])->values(),
            'labs' => $labs,
            'consults' => $consults->map(fn ($c) => (array) $c + ['to_doctor' => $doctors[$c->to_doctor_id] ?? null, 'from_doctor' => $doctors[$c->requested_by] ?? null])->values(),
            'plan' => [
                'planned_at' => $plan ? $plan->planned_at : null,
                'items' => collect(self::PLAN_ITEMS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label] + ((json_decode((string) optional($plan)->items, true) ?: [])[$key] ?? ['done' => false]))->values(),
            ],
            'doctors' => DB::table('doctors')->where('company_id', $cid)->whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'specialty']),
        ]);
    }

    public function addNote(Request $request, $id)
    {
        $this->canDoctor($request);
        $a = $this->stay($id);
        $v = $request->validate(['subjective' => 'nullable|string|max:4000', 'objective' => 'nullable|string|max:4000',
            'assessment' => 'nullable|string|max:4000', 'plan' => 'nullable|string|max:4000', 'doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')]]);
        abort_unless(array_filter(array_intersect_key($v, array_flip(['subjective', 'objective', 'assessment', 'plan']))), 422, 'Write the progress.');
        [, $docIds] = HospitalShifts::staffOf($request->user('api'));
        DB::table('hms_progress_notes')->insert($v + ['company_id' => $a->company_id, 'admission_id' => $a->id, 'patient_id' => $a->patient_id,
            'doctor_id' => $v['doctor_id'] ?? ($docIds[0] ?? $a->doctor_id), 'user_id' => $request->user('api')->id, 'noted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);

        return $this->chart($request, $a->id);
    }

    public function addVitals(Request $request, $id)
    {
        $this->canNurse($request);
        $a = $this->stay($id);
        $v = $request->validate(['temperature' => 'nullable|numeric|between:25,45', 'pulse' => 'nullable|integer|between:0,300', 'bp_systolic' => 'nullable|integer|between:0,300',
            'bp_diastolic' => 'nullable|integer|between:0,250', 'respiratory_rate' => 'nullable|integer|between:0,80', 'spo2' => 'nullable|integer|between:0,100',
            'pain' => 'nullable|integer|between:0,10', 'notes' => 'nullable|string|max:255']);
        abort_unless(array_filter($v, fn ($x) => $x !== null && $x !== ''), 422, 'Enter at least one value.');
        DB::table('hms_vitals')->insert($v + ['company_id' => $a->company_id, 'admission_id' => $a->id, 'patient_id' => $a->patient_id,
            'taken_at' => now(), 'user_id' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now()]);

        return $this->chart($request, $a->id);
    }

    public function addCareOrder(Request $request, $id)
    {
        $this->canDoctor($request);
        $a = $this->stay($id);
        $v = $request->validate(['kind' => 'required|in:diet,care,vitals,activity,other', 'description' => 'required|string|max:255',
            'every_hours' => 'nullable|integer|min:1|max:168', 'ends_at' => 'nullable|date']);
        [, $docIds] = HospitalShifts::staffOf($request->user('api'));
        DB::table('hms_care_orders')->insert($v + ['company_id' => $a->company_id, 'admission_id' => $a->id, 'patient_id' => $a->patient_id,
            'starts_at' => now(), 'status' => 'active', 'doctor_id' => $docIds[0] ?? $a->doctor_id, 'created_by' => $request->user('api')->id,
            'created_at' => now(), 'updated_at' => now()]);

        return $this->chart($request, $a->id);
    }

    private function careOrder($id)
    {
        $o = DB::table('hms_care_orders')->where('id', $id)->first();
        abort_unless($o, 404);
        $this->stay($o->admission_id); // acting company

        return $o;
    }

    public function stopCareOrder(Request $request, $id)
    {
        $this->canDoctor($request);
        $o = $this->careOrder($id);
        DB::table('hms_care_orders')->where('id', $o->id)->update(['status' => 'stopped', 'ends_at' => now(), 'updated_at' => now()]);

        return $this->chart($request, $o->admission_id);
    }

    public function careDone(Request $request, $id)
    {
        $this->canNurse($request);
        $o = $this->careOrder($id);
        $request->validate(['notes' => 'nullable|string|max:255']);
        DB::table('hms_care_logs')->insert(['company_id' => $o->company_id, 'care_order_id' => $o->id, 'done_at' => now(),
            'user_id' => $request->user('api')->id, 'notes' => $request->notes, 'created_at' => now(), 'updated_at' => now()]);

        return $this->chart($request, $o->admission_id);
    }

    public function addConsult(Request $request, $id)
    {
        $this->canDoctor($request);
        $a = $this->stay($id);
        $v = $request->validate(['to_doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')], 'specialty' => 'nullable|string|max:80',
            'reason' => 'required|string|max:2000', 'priority' => 'nullable|in:normal,urgent']);
        abort_unless(! empty($v['to_doctor_id']) || ! empty($v['specialty']), 422, 'Pick the specialist or the specialty.');
        [, $docIds] = HospitalShifts::staffOf($request->user('api'));
        DB::table('hms_consults')->insert($v + ['company_id' => $a->company_id, 'admission_id' => $a->id, 'patient_id' => $a->patient_id,
            'priority' => $v['priority'] ?? 'normal', 'requested_by' => $docIds[0] ?? $a->doctor_id, 'requested_user' => $request->user('api')->id,
            'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);

        return $this->chart($request, $a->id);
    }

    /** Consults: for the user's doctor(s) (mine=1: to them or to their specialty, still open) or all of the company. */
    public function consults(Request $request)
    {
        $this->canView($request);
        $q = DB::table('hms_consults as c')->join('patients as p', 'p.id', '=', 'c.patient_id')
            ->when(Admission::actingCompanyId(), fn ($w, $cid) => $w->where('c.company_id', $cid));
        if ($request->boolean('mine')) {
            [, $docIds] = HospitalShifts::staffOf($request->user('api'));
            $specs = DB::table('doctors')->whereIn('id', $docIds)->pluck('specialty')->filter()->all();
            $q->whereIn('c.status', ['requested', 'accepted'])
                ->where(fn ($w) => $w->whereIn('c.to_doctor_id', $docIds ?: [0])->orWhere(fn ($x) => $x->whereNull('c.to_doctor_id')->whereIn('c.specialty', $specs ?: ['~'])));
        }

        return response()->json(['consults' => $q->orderByRaw("c.priority = 'urgent' DESC")->orderByDesc('c.id')->limit(200)
            ->get(['c.*', 'p.name as patient_name', 'p.mrn'])]);
    }

    public function answerConsult(Request $request, $id)
    {
        $this->canView($request);
        $c = DB::table('hms_consults')->where('id', $id)->first();
        abort_unless($c, 404);
        if ($c->admission_id) {
            $this->stay($c->admission_id);
        }
        $v = $request->validate(['action' => 'required|in:accept,answer,cancel', 'answer' => 'nullable|required_if:action,answer|string|max:4000']);
        $set = ['updated_at' => now()];
        if ($v['action'] === 'accept') {
            $set['status'] = 'accepted';
        } elseif ($v['action'] === 'cancel') {
            $set['status'] = 'cancelled';
        } else {
            $set += ['status' => 'answered', 'answer' => $v['answer'], 'answered_at' => now(), 'answered_by' => $request->user('api')->id];
        }
        DB::table('hms_consults')->where('id', $c->id)->update($set);

        return $c->admission_id ? $this->chart($request, $c->admission_id) : response()->json(['success' => true]);
    }

    public function savePlan(Request $request, $id)
    {
        $this->canView($request);
        $a = $this->stay($id);
        $v = $request->validate(['planned_at' => 'required|date', 'items' => 'array']);
        $old = json_decode((string) DB::table('hms_discharge_plans')->where('admission_id', $a->id)->value('items'), true) ?: [];
        $items = [];
        foreach (array_keys(self::PLAN_ITEMS) as $key) {
            $done = (bool) ($v['items'][$key] ?? false);
            $items[$key] = $done ? (($old[$key]['done'] ?? false) ? $old[$key] : ['done' => true, 'by' => $this->userName($request->user('api')->id), 'at' => now()->toIso8601String()])
                : ['done' => false];
        }
        DB::table('hms_discharge_plans')->updateOrInsert(['admission_id' => $a->id], ['company_id' => $a->company_id, 'planned_at' => $v['planned_at'],
            'items' => json_encode($items), 'created_by' => $request->user('api')->id, 'updated_at' => now(), 'created_at' => now()]);

        return $this->chart($request, $a->id);
    }

    /** The epicrisis drafted from the stay (in the company's language), to review and save. */
    public function epicrisis(Request $request, $id)
    {
        $this->canView($request);
        $a = $this->stay($id);
        $es = DB::table('settings')->where('company_id', $a->company_id)->whereNull('deleted_at')->value('default_language') === 'es';
        $L = fn ($en, $esT) => $es ? $esT : $en;
        $p = $a->patient;
        $notes = DB::table('hms_progress_notes')->where('admission_id', $a->id)->whereNull('deleted_at')->orderBy('noted_at')->get();
        $surg = DB::table('hms_surgeries')->where('admission_id', $a->id)->whereNull('deleted_at')->where('status', '!=', 'cancelled')->get();
        $meds = DB::table('hms_medication_orders as o')->join('hms_treatments as t', 't.id', '=', 'o.treatment_id')->where('t.admission_id', $a->id)
            ->get(['o.medicine', 'o.dose', 'o.route', 'o.every_hours', 'o.days']);
        $labs = DB::table('lab_order_items as i')->join('lab_orders as o', 'o.id', '=', 'i.lab_order_id')->where('o.patient_id', $a->patient_id)
            ->where('o.ordered_at', '>=', $a->admitted_at)->whereNull('o.deleted_at')->whereIn('i.flag', ['high', 'low', 'critical'])->get(['i.test_name', 'i.result_value', 'i.unit', 'i.flag']);
        $plan = DB::table('hms_care_orders')->where('admission_id', $a->id)->where('status', 'active')->pluck('description');
        $d = fn ($dt) => $dt ? $dt->format('d/m/Y H:i') : '—';
        $out = [];
        $out[] = $L('DISCHARGE SUMMARY (EPICRISIS)', 'EPICRISIS (RESUMEN DE ALTA)');
        $out[] = $L('Patient', 'Paciente').': '.optional($p)->name.' · '.$L('Record', 'HC').' '.optional($p)->mrn.($p && $p->date_of_birth ? ' · '.$L('Age', 'Edad').' '.$p->age : '');
        $out[] = $L('Admitted', 'Ingreso').': '.$d($a->admitted_at).' · '.$L('Discharged', 'Egreso').': '.$d($a->discharged_at ?: now()).' · '.$L('Stay', 'Estancia').': '.$a->nights.' '.$L('nights', 'noches');
        $out[] = $L('Ward / bed', 'Sala / cama').': '.optional($a->ward)->name.' / '.optional($a->bed)->bed_number.' · '.$L('Attending doctor', 'Médico tratante').': '.(optional($a->doctor)->name ?: '—');
        $out[] = '';
        $out[] = $L('Reason for admission', 'Motivo de ingreso').': '.($a->reason ?: '—');
        $out[] = $L('Diagnosis', 'Diagnóstico').': '.($a->diagnosis ?: '—');
        $out[] = '';
        $out[] = $L('Course during the stay', 'Evolución durante la estancia').':';
        foreach ($notes as $n) {
            $out[] = '- '.\Carbon\Carbon::parse($n->noted_at)->format('d/m').': '.trim(($n->assessment ?: $n->subjective ?: $n->objective ?: '').($n->plan ? ' → '.$n->plan : ''));
        }
        if ($notes->isEmpty()) {
            $out[] = '- '.$L('No progress notes.', 'Sin notas de evolución.');
        }
        if ($surg->isNotEmpty()) {
            $out[] = '';
            $out[] = $L('Procedures', 'Procedimientos').':';
            foreach ($surg as $s) {
                $out[] = '- '.($s->procedure_performed ?: $s->planned_procedure).($s->post_op_diagnosis ? ' ('.$s->post_op_diagnosis.')' : '');
            }
        }
        if ($labs->isNotEmpty()) {
            $out[] = '';
            $out[] = $L('Relevant lab results', 'Laboratorio relevante').':';
            foreach ($labs as $l) {
                $out[] = '- '.$l->test_name.': '.$l->result_value.' '.$l->unit.' ('.$l->flag.')';
            }
        }
        if ($meds->isNotEmpty()) {
            $out[] = '';
            $out[] = $L('Treatment received', 'Tratamiento recibido').':';
            foreach ($meds as $m) {
                $out[] = '- '.$m->medicine.' '.$m->dose.' '.$m->route.($m->every_hours ? ' '.$L('every', 'cada').' '.$m->every_hours.' h' : '').($m->days ? ' · '.$m->days.' '.$L('days', 'días') : '');
            }
        }
        $out[] = '';
        $out[] = $L('Discharge instructions', 'Indicaciones al alta').':';
        $out[] = $plan->isNotEmpty() ? '- '.$plan->implode("\n- ") : '- ';
        $out[] = $L('Follow-up', 'Control').': ';

        return response()->json(['text' => implode("\n", $out), 'saved' => $a->discharge_summary]);
    }

    public function saveEpicrisis(Request $request, $id)
    {
        $this->canDoctor($request);
        $a = $this->stay($id);
        $request->validate(['text' => 'required|string|max:20000']);
        $a->update(['discharge_summary' => $request->text]);

        return response()->json(['success' => true]);
    }

    /**
     * Hospital control tower: the beds by status, alerts (cleaning +30 min,
     * discharged patients still in the room, long waits in the waiting room)
     * and the suggested bed for a new patient (?triage=1..5&department_id).
     */
    public function tower(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_wards', 'hms_admission_desk', 'hms_admissions', 'hms_setup'), 403);
        $beds = HospitalBed::with('ward')->whereNull('deleted_at')->get();
        $limits = [1 => 0, 2 => 10, 3 => 30, 4 => 60, 5 => 120];

        return response()->json([
            'counts' => $beds->countBy('status'),
            'alerts' => [
                'cleaning_late' => $beds->where('status', 'cleaning')->filter(fn ($b) => $b->status_changed_at && $b->status_changed_at->lt(now()->subMinutes(30)))
                    ->map(fn ($b) => ['bed_id' => $b->id, 'bed' => $b->bed_number, 'ward' => optional($b->ward)->name, 'minutes' => (int) $b->status_changed_at->diffInMinutes(now())])->values(),
                'to_free' => Admission::with('patient', 'bed', 'ward')->whereNull('deleted_at')->where('status', '!=', 'admitted')->whereNull('left_at')->whereNotNull('bed_id')->get()
                    ->map(fn ($a) => ['admission_id' => $a->id, 'patient' => optional($a->patient)->name, 'bed' => optional($a->bed)->bed_number, 'ward' => optional($a->ward)->name,
                        'minutes' => $a->discharged_at ? (int) $a->discharged_at->diffInMinutes(now()) : null])->values(),
                'waiting_long' => ErCase::with('patient')->whereNull('deleted_at')->where('status', '!=', 'closed')->whereNull('bed_id')->get()
                    ->filter(fn ($c) => $c->arrived_at && $c->arrived_at->diffInMinutes(now()) > ($limits[$c->triage_level] ?? 120))
                    ->map(fn ($c) => ['er_case_id' => $c->id, 'patient' => optional($c->patient)->name, 'triage' => $c->triage_level, 'minutes' => (int) $c->arrived_at->diffInMinutes(now())])->values(),
            ],
            'suggested' => $this->suggest($beds, (int) $request->get('triage', 3), $request->get('department_id'), $request->get('kind', 'ward')),
        ]);
    }

    /**
     * The best free bed: ER beds for kind=er; for a ward admission, critical
     * patients (triage 1-2) an intensive-care bed first, the doctor's
     * department's ward next, then the ward with most free beds (spread the load).
     */
    private function suggest($beds, int $triage, $departmentId, string $kind): ?array
    {
        $free = $beds->where('status', 'available')->filter(fn ($b) => $b->ward && $b->ward->is_active && ($kind === 'er' ? $b->ward->type === 'emergency' : $b->ward->type !== 'emergency'));
        if ($free->isEmpty()) {
            return null;
        }
        $freeByWard = $free->countBy('ward_id');
        $score = function ($b) use ($triage, $departmentId, $freeByWard) {
            $s = 0;
            $icu = in_array($b->ward->type, ['icu', 'nicu'], true);
            $s += $triage <= 2 ? ($icu ? 100 : 0) : ($icu ? -50 : 0);
            $s += $departmentId && (int) $b->ward->department_id === (int) $departmentId ? 30 : 0;
            $s += min(20, (int) ($freeByWard[$b->ward_id] ?? 0) * 2);

            return $s;
        };
        $best = $free->sortByDesc($score)->first();

        return ['bed_id' => $best->id, 'bed' => $best->bed_number, 'ward' => $best->ward->name, 'ward_type' => $best->ward->type];
    }
}
