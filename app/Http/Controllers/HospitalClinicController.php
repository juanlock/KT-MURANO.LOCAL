<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\HospitalInvoice;
use App\Models\HospitalInvoiceItem;
use App\Models\HospitalPayment;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Support\HospitalShifts;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Specialist clinic flow (Health Specialist companies, 2026-10-26) on top of
 * Hospital Management's patients / doctors / appointments / consultations:
 *
 *   booked -> (confirmed by email) -> pre-registered (portal) -> CHECK-IN
 *   (pay / insurance / later) -> VITAL SIGNS (nursing station) -> READY
 *   (the doctor is told) -> IN CONSULT (EMR) -> FINISHED (follow-up request,
 *   survey email) ; no-show / cancelled.
 *
 * appointments.flow_status: null (booked) | checked_in | ready | in_consult | finished.
 *   hms_reception        schedule (drag & drop), check-in, requests to schedule
 *   hms_nursing_station  vital signs
 *   hms_specialist       the consultation (EMR)
 *   hms_clinic_reports   the reports
 */
class HospitalClinicController extends Controller
{
    public const TEMPLATES = ['general', 'pediatrics', 'ophthalmology', 'cardiology', 'gynecology', 'psychology', 'psychiatry'];

    /** A patient waiting longer than this after being ready = the doctor is late. */
    public const LATE_MINUTES = 15;

    // ------------------------------------------------------------- schedule

    public function agenda(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_reception', 'hms_nursing_station', 'hms_specialist'), 403);
        $from = Carbon::parse($request->get('date', now()->toDateString()))->startOfDay();
        $days = max(1, min(7, (int) $request->get('days', 1)));
        $to = $from->copy()->addDays($days);

        $rows = Appointment::with('patient')->whereNull('deleted_at')->whereBetween('scheduled_at', [$from, $to])
            ->orderBy('scheduled_at')->get();

        return response()->json([
            'from' => $from->toDateString(), 'days' => $days,
            'doctors' => Doctor::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')
                ->get(['id', 'name', 'specialty', 'specialty_template', 'consultation_fee', 'availability']),
            'appointments' => $rows->map(fn ($a) => $this->presentAppointment($a))->values(),
            'requests' => $this->pendingRequests(),
            'late' => $this->lateDoctors(),
            'kpis' => $this->todayKpis(),
        ]);
    }

    /** New appointment (registered patient or a quick new one), with the doctor's slot checked. */
    public function store(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_reception'), 403);
        $request->validate([
            'patient_id' => ['nullable', 'required_without:new_patient.name', \App\Support\CompanyExists::rule('patients')],
            'new_patient.name' => 'nullable|required_without:patient_id|string|max:191',
            'new_patient.email' => 'nullable|email|max:191',
            'doctor_id' => ['required', \App\Support\CompanyExists::rule('doctors')],
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:5|max:240',
            'channel' => 'nullable|in:phone,web,whatsapp,walk_in,email',
            'reason' => 'nullable|string|max:255',
            'request_id' => 'nullable|integer',
        ]);
        $doctor = Doctor::findOrFail($request->doctor_id);
        $start = Carbon::parse($request->scheduled_at);
        $minutes = (int) ($request->duration_minutes ?: 20);
        if ($clash = $this->clash($doctor->id, $start, $minutes)) {
            return response()->json(['message' => 'The doctor already has an appointment then ('.$clash.').'], 422);
        }
        $a = DB::transaction(function () use ($request, $doctor, $start, $minutes) {
            $patientId = $request->patient_id ?: Patient::create([
                'mrn' => Patient::generateMrn(), 'name' => $request->input('new_patient.name'),
                'gender' => $request->input('new_patient.gender', 'other'), 'phone' => $request->input('new_patient.phone'),
                'email' => $request->input('new_patient.email'), 'date_of_birth' => $request->input('new_patient.date_of_birth'),
                'status' => 'active',
            ])->id;
            $first = ! PatientVisit::where('patient_id', $patientId)->whereNull('deleted_at')->exists();
            $a = Appointment::create([
                'reference' => Appointment::nextReference('APT'), 'patient_id' => $patientId, 'doctor_id' => $doctor->id,
                'department_id' => $doctor->department_id, 'scheduled_at' => $start, 'duration_minutes' => $minutes,
                'type' => $first ? 'consultation' : 'follow_up', 'status' => 'scheduled',
                'reason' => $request->reason, 'fee' => $doctor->consultation_fee, 'created_by' => optional($request->user('api'))->id,
            ]);
            DB::table('appointments')->where('id', $a->id)->update(['channel' => $request->channel ?: 'phone', 'is_first_visit' => $first,
                'request_id' => $request->request_id ?: null]);
            if ($request->request_id) {
                DB::table('hms_appointment_requests')->where('id', $request->request_id)->update(['status' => 'scheduled', 'appointment_id' => $a->id, 'updated_at' => now()]);
            }

            return $a;
        });

        return response()->json(['success' => true, 'id' => $a->id, 'reference' => $a->reference]);
    }

    /** Drag & drop: another time and / or doctor (only before check-in). */
    public function move(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_reception'), 403);
        $request->validate(['scheduled_at' => 'required|date', 'doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')]]);
        $a = Appointment::whereNull('deleted_at')->findOrFail($id);
        if ($a->flow_status || in_array($a->status, ['completed', 'cancelled', 'no_show'], true)) {
            return response()->json(['message' => 'Only an appointment not checked in yet can be moved.'], 422);
        }
        $doctorId = (int) ($request->doctor_id ?: $a->doctor_id);
        $start = Carbon::parse($request->scheduled_at);
        if ($clash = $this->clash($doctorId, $start, (int) ($a->duration_minutes ?: 20), $a->id)) {
            return response()->json(['message' => 'The doctor already has an appointment then ('.$clash.').'], 422);
        }
        $a->update(['scheduled_at' => $start, 'doctor_id' => $doctorId, 'department_id' => Doctor::whereKey($doctorId)->value('department_id')]);
        DB::table('appointments')->where('id', $a->id)->update(['reminder_sent_at' => null, 'confirmed_at' => null]);

        return response()->json(['success' => true]);
    }

    /** confirm / cancel / no_show / back to scheduled. */
    public function setStatus(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_reception'), 403);
        $request->validate(['status' => 'required|in:confirmed,cancelled,no_show,scheduled']);
        $a = Appointment::whereNull('deleted_at')->findOrFail($id);
        if ($a->flow_status && $request->status !== 'cancelled') {
            return response()->json(['message' => 'The patient is already checked in.'], 422);
        }
        $a->update(['status' => $request->status]);
        if ($request->status === 'confirmed') {
            DB::table('appointments')->where('id', $a->id)->update(['confirmed_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Check-in: the patient is here. Payment: 'pay' (the consultation is billed
     * and paid now -> a sale), 'insurance' (billed to the insurer, insurance
     * data kept on the patient) or 'later' (billed at the end).
     */
    public function checkIn(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_reception'), 403);
        $request->validate([
            'payment' => 'required|in:pay,insurance,later',
            'method' => 'nullable|string|max:32',
            'insurance_provider' => 'nullable|required_if:payment,insurance|string|max:191',
            'insurance_number' => 'nullable|string|max:64',
            'fee' => 'nullable|numeric|min:0',
        ]);
        $a = Appointment::whereNull('deleted_at')->findOrFail($id);
        if ($a->flow_status) {
            return response()->json(['message' => 'The patient is already checked in.'], 422);
        }
        if (in_array($a->status, ['cancelled', 'no_show', 'completed'], true)) {
            return response()->json(['message' => 'This appointment is closed.'], 422);
        }
        DB::transaction(function () use ($request, $a) {
            if ($request->filled('fee')) {
                $a->update(['fee' => $request->fee]);
            }
            if ($request->payment === 'insurance') {
                Patient::whereKey($a->patient_id)->update(['insurance_provider' => $request->insurance_provider, 'insurance_number' => $request->insurance_number]);
            }
            $invoiceId = $request->payment === 'later' ? null : $this->bill($a, $request->payment, $request->method, $request->insurance_provider);
            $a->update(['status' => 'arrived']);
            DB::table('appointments')->where('id', $a->id)->update([
                'flow_status' => 'checked_in', 'checked_in_at' => now(),
                'payment_status' => ['pay' => 'paid', 'insurance' => 'insurance', 'later' => 'pending'][$request->payment],
                'invoice_id' => $invoiceId,
            ]);
        });

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- nursing

    public function nursingQueue(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_nursing_station', 'hms_reception'), 403);
        $rows = Appointment::with('patient')->whereNull('deleted_at')->whereDate('scheduled_at', today())
            ->whereIn('flow_status', ['checked_in', 'ready'])->orderBy('checked_in_at')->get();
        $visits = PatientVisit::whereIn('appointment_id', $rows->pluck('id'))->whereNull('deleted_at')->get()->keyBy('appointment_id');

        return response()->json(['queue' => $rows->map(function ($a) use ($visits) {
            $v = $visits[$a->id] ?? null;
            $last = PatientVisit::where('patient_id', $a->patient_id)->whereNull('deleted_at')->where('id', '!=', optional($v)->id)
                ->whereNotNull('weight')->orderByDesc('visit_date')->first(['weight', 'height', 'visit_date']);

            return $this->presentAppointment($a) + [
                'vitals' => $v ? $v->only(['weight', 'height', 'temperature', 'pulse', 'bp_systolic', 'bp_diastolic', 'respiratory_rate', 'spo2']) : null,
                'last_weight' => optional($last)->weight, 'last_height' => optional($last)->height,
            ];
        })->values()]);
    }

    /** Vital signs -> the visit (opened here, linked to the appointment) -> "ready to go in". */
    public function saveVitals(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_nursing_station'), 403);
        $request->validate([
            'weight' => 'nullable|numeric|between:0,600', 'height' => 'nullable|numeric|between:0,300',
            'temperature' => 'nullable|numeric|between:25,45', 'pulse' => 'nullable|integer|between:0,300',
            'bp_systolic' => 'nullable|integer|between:0,300', 'bp_diastolic' => 'nullable|integer|between:0,250',
            'respiratory_rate' => 'nullable|integer|between:0,80', 'spo2' => 'nullable|integer|between:0,100',
            'head_circumference' => 'nullable|numeric|between:0,80', 'notes' => 'nullable|string|max:500',
        ]);
        $a = Appointment::whereNull('deleted_at')->findOrFail($id);
        if (! in_array($a->flow_status, ['checked_in', 'ready'], true)) {
            return response()->json(['message' => 'Check the patient in first.'], 422);
        }
        DB::transaction(function () use ($request, $a) {
            $v = $this->visitFor($a);
            $data = [];
            foreach (['weight', 'height', 'temperature', 'pulse', 'bp_systolic', 'bp_diastolic', 'respiratory_rate', 'spo2'] as $f) {
                $data[$f] = $request->filled($f) ? $request->input($f) : null;
            }
            $v->update($data);
            $spec = json_decode((string) $v->specialty_data, true) ?: [];
            if ($request->filled('head_circumference')) {
                $spec['head_circumference'] = (float) $request->head_circumference;
            }
            if ($request->filled('notes')) {
                $spec['nursing_note'] = $request->notes;
            }
            DB::table('patient_visits')->where('id', $v->id)->update(['specialty_data' => json_encode($spec)]);
            DB::table('appointments')->where('id', $a->id)->update(['flow_status' => 'ready', 'vitals_at' => now(), 'ready_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- doctor

    /** The doctor's day: the logged-in doctor (or ?doctor_id= for reception / admin). */
    public function myQueue(Request $request)
    {
        $user = $request->user('api');
        abort_unless(HospitalShifts::can($user, 'hms_specialist', 'hms_reception'), 403);
        [, $doctorIds] = HospitalShifts::staffOf($user);
        $doctorId = $request->filled('doctor_id') && HospitalShifts::can($user, 'hms_reception', 'hms_clinic_reports')
            ? (int) $request->doctor_id : ($doctorIds[0] ?? ($request->filled('doctor_id') ? (int) $request->doctor_id : null));
        $rows = $doctorId ? Appointment::with('patient')->whereNull('deleted_at')->where('doctor_id', $doctorId)
            ->whereDate('scheduled_at', $request->get('date', today()->toDateString()))->orderBy('scheduled_at')->get() : collect();

        return response()->json([
            'doctor' => $doctorId ? Doctor::find($doctorId, ['id', 'name', 'specialty', 'specialty_template']) : null,
            'is_doctor' => (bool) $doctorIds,
            'doctors' => HospitalShifts::can($user, 'hms_reception', 'hms_clinic_reports') || ! $doctorIds
                ? Doctor::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'specialty']) : [],
            'appointments' => $rows->map(fn ($a) => $this->presentAppointment($a))->values(),
        ]);
    }

    /** The EMR of a consultation: patient, problems, history, growth data, this visit, catalogs. */
    public function consult(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_specialist'), 403);
        $a = Appointment::with('patient', 'doctor')->whereNull('deleted_at')->findOrFail($id);
        $p = $a->patient;
        $visit = PatientVisit::where('appointment_id', $a->id)->whereNull('deleted_at')->first();
        $history = PatientVisit::with('doctor')->where('patient_id', $p->id)->whereNull('deleted_at')
            ->when($visit, fn ($q) => $q->where('id', '!=', $visit->id))->orderByDesc('visit_date')->limit(30)->get();
        $prescription = $visit ? \App\Models\Prescription::with('items')->where('visit_id', $visit->id)->whereNull('deleted_at')->first() : null;
        $labs = LabOrder::with('items')->where('patient_id', $p->id)->whereNull('deleted_at')->orderByDesc('ordered_at')->limit(15)->get();
        $cid = Patient::actingCompanyId('patients');

        return response()->json([
            'appointment' => $this->presentAppointment($a),
            'patient' => [
                'id' => $p->id, 'name' => $p->name, 'mrn' => $p->mrn, 'gender' => $p->gender, 'age' => $p->age,
                'date_of_birth' => optional($p->date_of_birth)->toDateString(), 'blood_group' => $p->blood_group,
                'allergies' => $p->allergies, 'chronic_conditions' => $p->chronic_conditions,
                'insurance_provider' => $p->insurance_provider, 'phone' => $p->phone, 'email' => $p->email,
            ],
            'pre_registration' => json_decode((string) DB::table('appointments')->where('id', $a->id)->value('pre_registration'), true),
            'problems' => DB::table('hms_patient_problems')->where('patient_id', $p->id)->whereNull('deleted_at')
                ->orderByRaw("status = 'active' desc")->orderByDesc('onset_on')->get(['id', 'problem', 'code', 'status', 'onset_on', 'notes']),
            'visit' => $visit ? $this->presentVisit($visit) + [
                'prescription' => $prescription ? $prescription->items->map(fn ($i) => $i->only(['product_id', 'medicine', 'dosage', 'frequency', 'duration', 'quantity', 'instructions']))->values() : [],
                'prescription_notes' => optional($prescription)->notes,
            ] : null,
            'history' => $history->map(fn ($v) => $this->presentVisit($v) + ['doctor' => optional($v->doctor)->name])->values(),
            'labs' => $labs->map(fn ($o) => [
                'id' => $o->id, 'reference' => $o->reference, 'ordered_at' => optional($o->ordered_at)->toIso8601String(), 'status' => $o->status,
                'visit_id' => $o->visit_id, 'test_ids' => $o->items->pluck('lab_test_id')->map(fn ($x) => (int) $x)->values(),
                'items' => $o->items->map(fn ($i) => ['test' => $i->test_name, 'result' => $i->result_value, 'unit' => $i->unit, 'flag' => $i->flag, 'range' => $i->normal_range])->values(),
            ])->values(),
            'catalog' => [
                'lab_tests' => DB::table('lab_tests')->whereNull('deleted_at')->where('is_active', 1)
                    ->when($cid !== null, fn ($q) => $q->where('company_id', $cid))->orderBy('category')->orderBy('name')->get(['id', 'name', 'category', 'price']),
                'medicines' => DB::table('products')->whereNull('deleted_at')->where('is_active', 1)->where('type', '!=', 'is_service')
                    ->when($cid !== null, fn ($q) => $q->where('company_id', $cid))->orderBy('name')
                    ->get(['id', 'name', 'generic_name', 'strength', 'dosage_form', 'prescription_required']),
                'doctors' => Doctor::whereNull('deleted_at')->where('is_active', 1)->where('id', '!=', $a->doctor_id)->orderBy('name')->get(['id', 'name', 'specialty']),
            ],
            'templates' => self::TEMPLATES,
        ]);
    }

    /** Start the consultation (the patient goes in). */
    public function start(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_specialist'), 403);
        $a = Appointment::whereNull('deleted_at')->findOrFail($id);
        if (! in_array($a->flow_status, ['checked_in', 'ready', 'in_consult'], true)) {
            return response()->json(['message' => 'The patient has not been checked in.'], 422);
        }
        DB::transaction(function () use ($a) {
            $this->visitFor($a);
            if ($a->flow_status !== 'in_consult') {
                DB::table('appointments')->where('id', $a->id)->update(['flow_status' => 'in_consult', 'consult_started_at' => now()]);
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * Save the EMR: SOAP (complaint / examination / diagnosis / plan), the
     * specialty template data, the prescription, problems, lab / imaging
     * orders, the follow-up and referrals (requests reception schedules).
     */
    public function save(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_specialist'), 403);
        $request->validate([
            'specialty_template' => 'nullable|in:'.implode(',', self::TEMPLATES),
            'specialty_data' => 'nullable|array',
            'problems' => 'nullable|array',
            'test_ids' => 'nullable|array',
            'follow_up.weeks' => 'nullable|integer|min:0|max:104',
            'follow_up.date' => 'nullable|date',
            'referral.doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
        ]);
        $a = Appointment::whereNull('deleted_at')->findOrFail($id);
        $userId = optional($request->user('api'))->id;
        DB::transaction(function () use ($request, $a, $userId) {
            $v = $this->visitFor($a);
            $followDate = $request->input('follow_up.date')
                ?: ($request->filled('follow_up.weeks') && (int) $request->input('follow_up.weeks') > 0 ? now()->addWeeks((int) $request->input('follow_up.weeks'))->toDateString() : null);
            $v->update([
                'complaint' => $request->complaint, 'examination' => $request->examination,
                'diagnosis' => $request->diagnosis, 'treatment_plan' => $request->treatment_plan,
                'follow_up_date' => $followDate,
            ]);
            $spec = array_merge(json_decode((string) $v->specialty_data, true) ?: [], (array) $request->input('specialty_data', []));
            DB::table('patient_visits')->where('id', $v->id)->update([
                'specialty_template' => $request->specialty_template ?: 'general', 'specialty_data' => json_encode($spec),
                'follow_up_note' => $request->input('follow_up.note'),
            ]);

            // Prescription (the consultations' own rules: replaced as a whole).
            $request->merge(['prescription_items' => $request->input('prescription_items', [])]);
            app(HospitalVisitController::class)->syncPrescription($request, $v, $userId);

            // Problem list (problem-oriented record).
            foreach ((array) $request->input('problems', []) as $pr) {
                if (! empty($pr['id'])) {
                    DB::table('hms_patient_problems')->where('id', $pr['id'])->where('patient_id', $a->patient_id)
                        ->update(['status' => ($pr['status'] ?? 'active') === 'resolved' ? 'resolved' : 'active', 'updated_at' => now()]);
                } elseif (! empty($pr['problem'])) {
                    DB::table('hms_patient_problems')->insert(['company_id' => $a->company_id, 'patient_id' => $a->patient_id,
                        'problem' => Str::limit($pr['problem'], 188), 'code' => $pr['code'] ?? null, 'status' => 'active',
                        'onset_on' => $pr['onset_on'] ?? now()->toDateString(), 'doctor_id' => $a->doctor_id,
                        'created_at' => now(), 'updated_at' => now()]);
                }
            }

            // Lab / imaging orders of this consultation (one order, its tests replaced).
            $tests = array_values(array_filter(array_map('intval', (array) $request->input('test_ids', []))));
            $order = LabOrder::where('visit_id', $v->id)->whereNull('deleted_at')->first();
            if ($tests && ! $order) {
                $order = LabOrder::create(['reference' => LabOrder::nextReference('LAB'), 'patient_id' => $a->patient_id, 'doctor_id' => $a->doctor_id,
                    'visit_id' => $v->id, 'ordered_at' => now(), 'priority' => 'routine', 'status' => 'ordered', 'created_by' => $userId]);
            }
            if ($order && $order->status === 'ordered') {
                $this->syncLabTests($order, $tests);
            }

            // Follow-up -> a request for reception (with the studies to bring).
            $req = DB::table('hms_appointment_requests')->where('from_visit_id', $v->id)->where('kind', 'follow_up')->first();
            if ($followDate) {
                $row = ['company_id' => $a->company_id, 'patient_id' => $a->patient_id, 'doctor_id' => $a->doctor_id, 'from_visit_id' => $v->id,
                    'from_doctor_id' => $a->doctor_id, 'kind' => 'follow_up', 'wanted_on' => $followDate,
                    'reason' => $request->input('follow_up.note'), 'studies' => $request->input('follow_up.studies'), 'updated_at' => now()];
                $req ? DB::table('hms_appointment_requests')->where('id', $req->id)->where('status', 'pending')->update($row)
                    : DB::table('hms_appointment_requests')->insert($row + ['status' => 'pending', 'created_by' => $userId, 'created_at' => now()]);
            } elseif ($req && $req->status === 'pending') {
                DB::table('hms_appointment_requests')->where('id', $req->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            // Referral (interconsultation) to another specialist of the clinic.
            if ($request->filled('referral.doctor_id')) {
                $exists = DB::table('hms_appointment_requests')->where('from_visit_id', $v->id)->where('kind', 'referral')
                    ->where('doctor_id', $request->input('referral.doctor_id'))->exists();
                if (! $exists) {
                    DB::table('hms_appointment_requests')->insert(['company_id' => $a->company_id, 'patient_id' => $a->patient_id,
                        'doctor_id' => $request->input('referral.doctor_id'), 'from_visit_id' => $v->id, 'from_doctor_id' => $a->doctor_id,
                        'kind' => 'referral', 'wanted_on' => now()->addDays(7)->toDateString(), 'reason' => $request->input('referral.reason'),
                        'status' => 'pending', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });

        return response()->json(['success' => true]);
    }

    /** End of the consultation: visit + appointment completed, bill if not yet, the satisfaction survey emailed. */
    public function finish(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_specialist'), 403);
        $a = Appointment::whereNull('deleted_at')->findOrFail($id);
        $survey = DB::transaction(function () use ($a) {
            $v = $this->visitFor($a);
            $v->update(['status' => 'completed']);
            $a->update(['status' => 'completed']);
            $upd = ['flow_status' => 'finished', 'consult_ended_at' => now()];
            if (! $a->consult_started_at) {
                $upd['consult_started_at'] = now();
            }
            if (! DB::table('appointments')->where('id', $a->id)->value('invoice_id')) {
                $upd['invoice_id'] = $this->bill($a, 'later');
            }
            DB::table('appointments')->where('id', $a->id)->update($upd);

            return $this->surveyFor($a);
        });
        $sent = $survey ? \App\Services\BookingNotifier::clinicSurvey((int) $a->company_id, (int) $survey) : null;
        if ($sent) {
            DB::table('hms_surveys')->where('id', $survey)->update(['sent_at' => now()]);
        }

        return response()->json(['success' => true, 'survey_sent_to' => $sent]);
    }

    // ------------------------------------------------------------- requests

    public function requests(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_reception'), 403);

        return response()->json(['requests' => $this->pendingRequests()]);
    }

    public function cancelRequest(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_reception'), 403);
        $cid = Patient::actingCompanyId('patients');
        DB::table('hms_appointment_requests')->where('id', $id)->when($cid !== null, fn ($q) => $q->where('company_id', $cid))
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- reports

    public function reports(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_clinic_reports'), 403);
        $from = Carbon::parse($request->get('start_date', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->get('end_date', now()->toDateString()))->endOfDay();
        $rows = Appointment::whereNull('deleted_at')->whereBetween('scheduled_at', [$from, $to])->where('scheduled_at', '<=', now())->get();
        $doctors = Doctor::whereNull('deleted_at')->get(['id', 'name', 'specialty'])->keyBy('id');
        $surveys = DB::table('hms_surveys')->whereIn('appointment_id', $rows->pluck('id'))->whereNotNull('score')->get(['appointment_id', 'doctor_id', 'score']);
        $invoices = HospitalInvoice::whereIn('id', DB::table('appointments')->whereIn('id', $rows->pluck('id'))->whereNotNull('invoice_id')->pluck('invoice_id'))
            ->whereNull('deleted_at')->where('status', '!=', 'cancelled')->get(['id', 'total', 'paid']);
        $invByAppt = DB::table('appointments')->whereIn('id', $rows->pluck('id'))->whereNotNull('invoice_id')->pluck('invoice_id', 'id');
        $invTotals = $invoices->keyBy('id');
        $nps = function ($scores) {
            $n = $scores->count();

            return $n ? (int) round((($scores->filter(fn ($s) => $s >= 9)->count() - $scores->filter(fn ($s) => $s <= 6)->count()) / $n) * 100) : null;
        };
        $stats = function ($set) use ($surveys, $invByAppt, $invTotals, $nps) {
            $done = $set->where('status', 'completed');
            $closed = $set->whereIn('status', ['completed', 'no_show']);
            $wait = $done->filter(fn ($a) => $a->checked_in_at && $a->consult_started_at)->map(fn ($a) => Carbon::parse($a->checked_in_at)->diffInMinutes(Carbon::parse($a->consult_started_at)));
            $consult = $done->filter(fn ($a) => $a->consult_started_at && $a->consult_ended_at)->map(fn ($a) => Carbon::parse($a->consult_started_at)->diffInMinutes(Carbon::parse($a->consult_ended_at)));
            $scores = $surveys->whereIn('appointment_id', $set->pluck('id'))->pluck('score');

            return [
                'booked' => $set->count(), 'attended' => $done->count(), 'no_show' => $set->where('status', 'no_show')->count(),
                'cancelled' => $set->where('status', 'cancelled')->count(),
                'no_show_rate' => $closed->count() ? round($set->where('status', 'no_show')->count() * 100 / $closed->count(), 1) : null,
                'revenue' => round($set->sum(fn ($a) => (float) optional($invTotals[$invByAppt[$a->id] ?? 0] ?? null)->total), 2),
                'collected' => round($set->sum(fn ($a) => (float) optional($invTotals[$invByAppt[$a->id] ?? 0] ?? null)->paid), 2),
                'avg_wait' => $wait->count() ? (int) round($wait->avg()) : null,
                'avg_consult' => $consult->count() ? (int) round($consult->avg()) : null,
                'first_visits' => $done->where('is_first_visit', true)->count(),
                'nps' => $nps($scores), 'surveys' => $scores->count(),
            ];
        };

        return response()->json([
            'totals' => $stats($rows),
            'by_doctor' => $rows->groupBy('doctor_id')->map(fn ($set, $did) => ['doctor_id' => $did, 'doctor' => optional($doctors[$did] ?? null)->name,
                'specialty' => optional($doctors[$did] ?? null)->specialty] + $stats($set))->sortByDesc('attended')->values(),
            'daily' => $rows->groupBy(fn ($a) => Carbon::parse($a->scheduled_at)->toDateString())->map(fn ($set, $d) => [
                'date' => $d, 'attended' => $set->where('status', 'completed')->count(), 'no_show' => $set->where('status', 'no_show')->count(),
            ])->sortKeys()->values(),
            'comments' => DB::table('hms_surveys')->whereIn('appointment_id', $rows->pluck('id'))->whereNotNull('comment')
                ->orderByDesc('answered_at')->limit(20)->get(['score', 'comment', 'answered_at', 'doctor_id'])
                ->map(fn ($s) => (array) $s + ['doctor' => optional($doctors[$s->doctor_id] ?? null)->name]),
        ]);
    }

    // ------------------------------------------------------------- helpers

    /** The consultation (patient_visits row) of the appointment, opened when missing. */
    private function visitFor(Appointment $a): PatientVisit
    {
        $v = PatientVisit::where('appointment_id', $a->id)->whereNull('deleted_at')->first();
        if ($v) {
            return $v;
        }
        $v = PatientVisit::create([
            'reference' => PatientVisit::nextReference('VIS'), 'patient_id' => $a->patient_id, 'doctor_id' => $a->doctor_id,
            'appointment_id' => $a->id, 'department_id' => $a->department_id, 'visit_date' => now(),
            'type' => $a->type === 'follow_up' ? 'follow_up' : 'opd', 'status' => 'open', 'fee' => (float) $a->fee,
            'complaint' => $a->reason, 'created_by' => auth('api')->id(),
        ]);
        DB::table('patient_visits')->where('id', $v->id)->update(['specialty_template' => Doctor::whereKey($a->doctor_id)->value('specialty_template') ?: 'general']);

        return $v->fresh();
    }

    /** The consultation bill (a hospital invoice -> its sale); 'pay' records the payment too. Returns the invoice id. */
    private function bill(Appointment $a, string $mode, ?string $method = null, ?string $insurer = null): ?int
    {
        $existing = DB::table('appointments')->where('id', $a->id)->value('invoice_id');
        if ($existing) {
            return (int) $existing;
        }
        $fee = (float) $a->fee;
        if ($fee <= 0) {
            return null;
        }
        $doctor = Doctor::find($a->doctor_id);
        $inv = HospitalInvoice::create([
            'company_id' => $a->company_id, 'reference' => HospitalInvoice::nextReference('INV'), 'patient_id' => $a->patient_id,
            'invoice_date' => now()->toDateString(), 'discount' => 0, 'tax' => 0, 'subtotal' => $fee, 'total' => $fee, 'paid' => 0,
            'status' => 'unpaid', 'notes' => trim('Appointment '.$a->reference.($insurer ? ' - '.$insurer : '')), 'created_by' => auth('api')->id(),
        ]);
        HospitalInvoiceItem::create(['invoice_id' => $inv->id, 'type' => 'consultation',
            'description' => 'Consultation — '.optional($doctor)->name, 'quantity' => 1, 'unit_price' => $fee, 'total' => $fee]);
        \App\Services\HospitalSale::sync($inv);
        if ($mode === 'pay') {
            $pay = HospitalPayment::create(['company_id' => $a->company_id, 'reference' => HospitalPayment::nextReference('PAY'),
                'invoice_id' => $inv->id, 'patient_id' => $a->patient_id, 'paid_on' => now()->toDateString(), 'amount' => $fee,
                'method' => $method ?: 'cash', 'created_by' => auth('api')->id()]);
            $inv->update(['paid' => $fee, 'status' => 'paid']);
            \App\Services\HospitalSale::syncPayment($pay, $inv->fresh());
        }

        return (int) $inv->id;
    }

    private function surveyFor(Appointment $a): ?int
    {
        $id = DB::table('hms_surveys')->where('appointment_id', $a->id)->value('id');

        return $id ? (int) $id : (int) DB::table('hms_surveys')->insertGetId([
            'company_id' => $a->company_id, 'appointment_id' => $a->id, 'patient_id' => $a->patient_id, 'doctor_id' => $a->doctor_id,
            'token' => Str::random(40), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function syncLabTests(LabOrder $order, array $testIds): void
    {
        $tests = DB::table('lab_tests')->whereIn('id', $testIds ?: [0])->get(['id', 'name', 'price', 'unit', 'normal_range'])->keyBy('id');
        DB::table('lab_order_items')->where('lab_order_id', $order->id)->whereNotIn('lab_test_id', $tests->keys()->all() ?: [0])->delete();
        $have = DB::table('lab_order_items')->where('lab_order_id', $order->id)->pluck('lab_test_id')->all();
        foreach ($tests as $t) {
            if (! in_array($t->id, $have)) {
                DB::table('lab_order_items')->insert(['lab_order_id' => $order->id, 'lab_test_id' => $t->id, 'test_name' => $t->name,
                    'price' => $t->price, 'unit' => $t->unit, 'normal_range' => $t->normal_range, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $order->update(['total' => (float) DB::table('lab_order_items')->where('lab_order_id', $order->id)->sum('price')]);
        if (! $tests->count()) {
            $order->delete();
        }
    }

    /** Another appointment of the doctor overlapping [start, start + minutes) -> its reference. */
    private function clash(int $doctorId, Carbon $start, int $minutes, ?int $ignore = null): ?string
    {
        $end = $start->copy()->addMinutes($minutes);
        foreach (Appointment::whereNull('deleted_at')->where('doctor_id', $doctorId)->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))
            ->whereBetween('scheduled_at', [$start->copy()->subHours(4), $end])->get(['reference', 'scheduled_at', 'duration_minutes']) as $o) {
            $oEnd = $o->scheduled_at->copy()->addMinutes($o->duration_minutes ?: 20);
            if ($o->scheduled_at < $end && $oEnd > $start) {
                return $o->reference;
            }
        }

        return null;
    }

    private function pendingRequests()
    {
        $cid = Patient::actingCompanyId('patients');
        $rows = DB::table('hms_appointment_requests as r')->join('patients as p', 'p.id', '=', 'r.patient_id')
            ->leftJoin('doctors as d', 'd.id', '=', 'r.doctor_id')->leftJoin('doctors as f', 'f.id', '=', 'r.from_doctor_id')
            ->where('r.status', 'pending')->when($cid !== null, fn ($q) => $q->where('r.company_id', $cid))
            ->orderBy('r.wanted_on')->limit(200)
            ->get(['r.id', 'r.kind', 'r.wanted_on', 'r.reason', 'r.studies', 'r.patient_id', 'r.doctor_id', 'p.name as patient', 'p.phone',
                'p.mrn', 'd.name as doctor', 'f.name as from_doctor']);

        return $rows->map(fn ($r) => (array) $r + ['overdue' => $r->wanted_on && $r->wanted_on < today()->toDateString()])->values();
    }

    /** Doctors with a patient ready and waiting longer than LATE_MINUTES (today). */
    private function lateDoctors()
    {
        return Appointment::with('doctor', 'patient')->whereNull('deleted_at')->whereDate('scheduled_at', today())
            ->where('flow_status', 'ready')->get()
            ->filter(fn ($a) => DB::table('appointments')->where('id', $a->id)->value('ready_at') < now()->subMinutes(self::LATE_MINUTES)->toDateTimeString()
                && $a->scheduled_at < now()->subMinutes(self::LATE_MINUTES))
            ->map(fn ($a) => ['appointment_id' => $a->id, 'doctor' => optional($a->doctor)->name, 'patient' => optional($a->patient)->name,
                'minutes' => (int) Carbon::parse(DB::table('appointments')->where('id', $a->id)->value('ready_at'))->diffInMinutes(now())])->values();
    }

    private function todayKpis(): array
    {
        $rows = DB::table('appointments')->whereNull('deleted_at')->whereDate('scheduled_at', today())
            ->when(($cid = Patient::actingCompanyId('patients')) !== null, fn ($q) => $q->where('company_id', $cid))->get(['status', 'flow_status']);

        return [
            'booked' => $rows->whereNotIn('status', ['cancelled'])->count(),
            'confirmed' => $rows->where('status', 'confirmed')->count(),
            'waiting' => $rows->whereIn('flow_status', ['checked_in', 'ready'])->count(),
            'in_consult' => $rows->where('flow_status', 'in_consult')->count(),
            'finished' => $rows->where('flow_status', 'finished')->count(),
            'no_show' => $rows->where('status', 'no_show')->count(),
        ];
    }

    private function presentAppointment(Appointment $a): array
    {
        $x = (array) DB::table('appointments')->where('id', $a->id)->first(['flow_status', 'checked_in_at', 'ready_at', 'consult_started_at',
            'consult_ended_at', 'is_first_visit', 'channel', 'payment_status', 'invoice_id', 'pre_registered_at', 'confirmed_at']);
        $iso = fn ($v) => $v ? Carbon::parse($v)->toIso8601String() : null;

        return [
            'id' => $a->id, 'reference' => $a->reference, 'patient_id' => $a->patient_id,
            'patient' => optional($a->patient)->name, 'mrn' => optional($a->patient)->mrn, 'phone' => optional($a->patient)->phone,
            'age' => optional($a->patient)->age, 'gender' => optional($a->patient)->gender, 'allergies' => optional($a->patient)->allergies,
            'insurance_provider' => optional($a->patient)->insurance_provider,
            'doctor_id' => $a->doctor_id, 'scheduled_at' => optional($a->scheduled_at)->toIso8601String(), 'duration' => (int) ($a->duration_minutes ?: 20),
            'type' => $a->type, 'status' => $a->status, 'reason' => $a->reason, 'fee' => (float) $a->fee,
            'flow' => $x['flow_status'] ?? null, 'checked_in_at' => $iso($x['checked_in_at'] ?? null), 'ready_at' => $iso($x['ready_at'] ?? null),
            'consult_started_at' => $iso($x['consult_started_at'] ?? null), 'consult_ended_at' => $iso($x['consult_ended_at'] ?? null),
            'first_visit' => (bool) ($x['is_first_visit'] ?? false), 'channel' => $x['channel'] ?? null,
            'payment' => $x['payment_status'] ?? null, 'invoice_id' => $x['invoice_id'] ?? null,
            'pre_registered' => (bool) ($x['pre_registered_at'] ?? null), 'confirmed' => (bool) ($x['confirmed_at'] ?? null) || $a->status === 'confirmed',
        ];
    }

    private function presentVisit(PatientVisit $v): array
    {
        $row = DB::table('patient_visits')->where('id', $v->id)->first(['specialty_template', 'specialty_data', 'follow_up_note']);

        return [
            'id' => $v->id, 'reference' => $v->reference, 'date' => optional($v->visit_date)->toIso8601String(), 'status' => $v->status,
            'weight' => $v->weight !== null ? (float) $v->weight : null, 'height' => $v->height !== null ? (float) $v->height : null,
            'temperature' => $v->temperature !== null ? (float) $v->temperature : null, 'pulse' => $v->pulse, 'bp_systolic' => $v->bp_systolic,
            'bp_diastolic' => $v->bp_diastolic, 'respiratory_rate' => $v->respiratory_rate, 'spo2' => $v->spo2,
            'complaint' => $v->complaint, 'examination' => $v->examination, 'diagnosis' => $v->diagnosis, 'treatment_plan' => $v->treatment_plan,
            'follow_up_date' => optional($v->follow_up_date)->toDateString(), 'follow_up_note' => $row->follow_up_note ?? null,
            'specialty_template' => $row->specialty_template ?? 'general', 'specialty_data' => json_decode((string) ($row->specialty_data ?? ''), true) ?: [],
        ];
    }
}
