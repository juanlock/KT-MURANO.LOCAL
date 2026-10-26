<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Patient portal (hospital phase 4, 2026-10-25). A patient IS a customer
 * (patients.client_id), so the customer's portal login shows, for each of
 * their patient records in that company:
 *  - the current stay: where (ward / room / bed), day N, the day's status the
 *    nurses set for the family (stable, improving...) -- never diagnoses,
 *    results or medication -- the diet and the visit hours;
 *  - after a discharge (60 days): home prescriptions, home care, the care
 *    videos picked by the doctor and the follow-up date;
 *  - upcoming appointments (also reminded by email 24 h before).
 */
class PortalHospitalController extends Controller
{
    public function index(Request $request)
    {
        $portalClient = Auth::guard('portal')->user();
        if ((int) $portalClient->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }
        $companyId = (int) $portalClient->client->company_id;
        $patients = DB::table('patients')->where('company_id', $companyId)->where('client_id', $portalClient->client_id)
            ->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'mrn']);
        $visitHours = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('hms_visit_hours');

        return response()->json([
            'visit_hours' => $visitHours,
            // Specialist clinic (2026-10-26): the patient books online, pre-registers, sees prescriptions and surveys.
            'clinic' => \App\Support\BusinessTypes::of($companyId) === 'health_specialist',
            'doctors' => DB::table('doctors')->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('specialty')->orderBy('name')->get(['id', 'name', 'specialty', 'consultation_fee']),
            'patients' => $patients->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'mrn' => $p->mrn,
                'stay' => $this->stay($p->id, $companyId),
                'discharge' => $this->discharge($p->id, $companyId),
                'appointments' => $this->appointments($p->id, $companyId),
                'prescriptions' => $this->prescriptions($p->id, $companyId),
                'surveys' => DB::table('hms_surveys as s')->leftJoin('doctors as d', 'd.id', '=', 's.doctor_id')
                    ->where('s.patient_id', $p->id)->whereNull('s.answered_at')->where('s.created_at', '>=', now()->subDays(30))
                    ->get(['s.token', 's.created_at', 'd.name as doctor'])->map(fn ($s) => (array) $s + ['url' => '/survey/'.$s->token]),
            ])->values(),
        ]);
    }

    /** Free 20-minute slots of a doctor on a day (08:00-18:00, future only, no clash). */
    public function slots(Request $request)
    {
        [$companyId] = $this->portalCompany();
        $request->validate(['doctor_id' => 'required|integer', 'date' => 'required|date']);
        $doctor = DB::table('doctors')->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', 1)->find($request->doctor_id);
        abort_unless($doctor, 404);
        $day = \Carbon\Carbon::parse($request->date)->startOfDay();
        if ($day->isSunday() || $day->lt(today()) || $day->gt(today()->addDays(60))) {
            return response()->json(['slots' => []]);
        }
        $taken = DB::table('appointments')->where('doctor_id', $doctor->id)->whereNull('deleted_at')->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereDate('scheduled_at', $day)->get(['scheduled_at', 'duration_minutes']);
        $slots = [];
        for ($t = $day->copy()->setTime(8, 0); $t < $day->copy()->setTime(18, 0); $t->addMinutes(20)) {
            if ($t <= now()->addHour()) {
                continue;
            }
            $end = $t->copy()->addMinutes(20);
            $busy = $taken->first(fn ($a) => \Carbon\Carbon::parse($a->scheduled_at) < $end
                && \Carbon\Carbon::parse($a->scheduled_at)->addMinutes($a->duration_minutes ?: 20) > $t);
            if (! $busy) {
                $slots[] = $t->format('H:i');
            }
        }

        return response()->json(['slots' => $slots]);
    }

    /** Book from the portal (channel web): the patient must be this customer's. */
    public function book(Request $request)
    {
        [$companyId, $clientId] = $this->portalCompany();
        $request->validate(['patient_id' => 'required|integer', 'doctor_id' => 'required|integer', 'date' => 'required|date',
            'time' => 'required|date_format:H:i', 'reason' => 'nullable|string|max:255']);
        $patient = DB::table('patients')->where('company_id', $companyId)->where('client_id', $clientId)->whereNull('deleted_at')->find($request->patient_id);
        $doctor = DB::table('doctors')->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', 1)->find($request->doctor_id);
        abort_unless($patient && $doctor, 404);
        $open = DB::table('appointments')->where('patient_id', $patient->id)->whereNull('deleted_at')->whereIn('status', ['scheduled', 'confirmed'])
            ->where('scheduled_at', '>=', now())->count();
        if ($open >= 3) {
            return response()->json(['message' => __('portal.hosp_book_limit')], 422);
        }
        $at = \Carbon\Carbon::parse($request->date.' '.$request->time);
        $free = in_array($request->time, $this->slots(new Request(['doctor_id' => $doctor->id, 'date' => $request->date]))->getData(true)['slots'], true);
        if (! $free) {
            return response()->json(['message' => __('portal.hosp_slot_taken')], 422);
        }
        $first = ! DB::table('patient_visits')->where('patient_id', $patient->id)->whereNull('deleted_at')->exists();
        $ref = \App\Models\Appointment::nextReference('APT');
        DB::table('appointments')->insert([
            'company_id' => $companyId, 'reference' => $ref, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'department_id' => $doctor->department_id, 'scheduled_at' => $at, 'duration_minutes' => 20,
            'type' => $first ? 'consultation' : 'follow_up', 'status' => 'scheduled', 'reason' => $request->reason,
            'fee' => $doctor->consultation_fee, 'channel' => 'web', 'is_first_visit' => $first, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'reference' => $ref]);
    }

    /** Pre-registration from home: why the patient comes, since when, medication, allergies, history. */
    public function preRegister(Request $request, $reference)
    {
        [$companyId, $clientId] = $this->portalCompany();
        $data = $request->validate(['reason' => 'nullable|string|max:500', 'symptoms_since' => 'nullable|string|max:120',
            'current_medication' => 'nullable|string|max:500', 'allergies' => 'nullable|string|max:255', 'history' => 'nullable|string|max:1000']);
        $a = DB::table('appointments as a')->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->where('a.company_id', $companyId)->where('p.client_id', $clientId)->where('a.reference', $reference)->whereNull('a.deleted_at')
            ->whereIn('a.status', ['scheduled', 'confirmed'])->first(['a.id', 'a.patient_id', 'p.allergies']);
        abort_unless($a, 404);
        DB::table('appointments')->where('id', $a->id)->update(['pre_registration' => json_encode(array_filter($data)), 'pre_registered_at' => now(),
            'reason' => $data['reason'] ?? DB::raw('reason'), 'updated_at' => now()]);
        if (! empty($data['allergies']) && empty($a->allergies)) {
            DB::table('patients')->where('id', $a->patient_id)->update(['allergies' => $data['allergies']]);
        }

        return response()->json(['success' => true]);
    }

    /** A prescription of the patient, printable (portal). */
    public function prescription($reference)
    {
        [$companyId, $clientId] = $this->portalCompany();
        $rx = DB::table('prescriptions as r')->join('patients as p', 'p.id', '=', 'r.patient_id')->leftJoin('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->where('r.company_id', $companyId)->where('p.client_id', $clientId)->where('r.reference', $reference)->whereNull('r.deleted_at')
            ->first(['r.id', 'r.reference', 'r.prescribed_on', 'r.notes', 'p.name', 'p.mrn', 'd.name as doctor', 'd.specialty']);
        abort_unless($rx, 404);
        $items = DB::table('prescription_items')->where('prescription_id', $rx->id)->get();
        $company = DB::table('companies')->where('id', $companyId)->value('name');
        $e = fn ($v) => e((string) $v);
        $rows = $items->map(fn ($i) => "<tr><td><b>{$e($i->medicine)}</b></td><td>{$e($i->dosage)}</td><td>{$e($i->frequency)}</td><td>{$e($i->duration)}</td><td>{$e($i->instructions)}</td></tr>")->implode('');

        return response("<!doctype html><html><head><meta charset='utf-8'><title>{$e($rx->reference)}</title><style>body{font-family:Arial;padding:24px;font-size:13px}table{width:100%;border-collapse:collapse;margin-top:12px}td,th{border-bottom:1px solid #ccc;padding:6px;text-align:left}</style></head><body>"
            ."<h2>{$e($company)}</h2><div><b>{$e($rx->doctor)}</b> · {$e($rx->specialty)}</div><hr><div>{$e($rx->name)} · {$e($rx->mrn)} · {$e($rx->prescribed_on)} · {$e($rx->reference)}</div>"
            ."<table><tr><th>".__('portal.hosp_rx_medicine').'</th><th>'.__('portal.hosp_rx_dose').'</th><th>'.__('portal.hosp_rx_every').'</th><th>'.__('portal.hosp_rx_for').'</th><th>'.__('portal.hosp_rx_instructions')."</th></tr>{$rows}</table>"
            .($rx->notes ? "<p>{$e($rx->notes)}</p>" : '')."<script>window.print()</script></body></html>")->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** The customer's company and client id (portal login), 403 when the portal is off. */
    private function portalCompany(): array
    {
        $portalClient = Auth::guard('portal')->user();
        if ((int) $portalClient->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }

        return [(int) $portalClient->client->company_id, (int) $portalClient->client_id];
    }

    /** The patient's prescriptions (last 12 months) -- no diagnoses, just what to take. */
    private function prescriptions(int $patientId, int $companyId)
    {
        return DB::table('prescriptions as r')->leftJoin('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->where('r.company_id', $companyId)->where('r.patient_id', $patientId)->whereNull('r.deleted_at')
            ->where('r.prescribed_on', '>=', now()->subYear()->toDateString())->orderByDesc('r.prescribed_on')->limit(20)
            ->get(['r.reference', 'r.prescribed_on', 'd.name as doctor', 'd.specialty'])
            ->map(fn ($r) => (array) $r + ['items' => DB::table('prescription_items')->join('prescriptions', 'prescriptions.id', '=', 'prescription_items.prescription_id')
                ->where('prescriptions.reference', $r->reference)->pluck('prescription_items.medicine')->all()]);
    }

    private function stay(int $patientId, int $companyId): ?array
    {
        $a = DB::table('admissions as a')->leftJoin('hospital_wards as w', 'w.id', '=', 'a.ward_id')
            ->leftJoin('hospital_beds as b', 'b.id', '=', 'a.bed_id')->leftJoin('hms_rooms as r', 'r.id', '=', 'b.room_id')
            ->leftJoin('hms_floors as f', 'f.id', '=', 'r.floor_id')->leftJoin('doctors as d', 'd.id', '=', 'a.doctor_id')
            ->where('a.company_id', $companyId)->where('a.patient_id', $patientId)->whereNull('a.deleted_at')
            ->where(fn ($q) => $q->where('a.status', 'admitted')->orWhere(fn ($w) => $w->whereNotNull('a.discharged_at')->whereNull('a.left_at')))
            ->orderByDesc('a.admitted_at')
            ->first(['a.id', 'a.admitted_at', 'a.status', 'a.portal_status', 'a.portal_note', 'a.portal_status_at',
                'w.name as ward', 'b.bed_number as bed', 'r.name as room', 'f.name as floor', 'd.name as doctor']);
        if (! $a) {
            return null;
        }
        $diet = DB::table('hms_care_orders')->where('admission_id', $a->id)->where('kind', 'diet')->where('status', 'active')->pluck('description')->values();

        return [
            'admitted_at' => $a->admitted_at, 'day' => (int) \Carbon\Carbon::parse($a->admitted_at)->startOfDay()->diffInDays(now()->startOfDay()) + 1,
            'ward' => $a->ward, 'room' => $a->room, 'bed' => $a->bed, 'floor' => $a->floor, 'doctor' => $a->doctor,
            'status' => $a->portal_status, 'note' => $a->portal_note, 'status_at' => $a->portal_status_at,
            'discharged' => $a->status !== 'admitted', 'diet' => $diet,
            'planned_discharge' => DB::table('hms_discharge_plans')->where('admission_id', $a->id)->value('planned_at'),
        ];
    }

    private function discharge(int $patientId, int $companyId): ?array
    {
        $row = DB::table('admissions as a')->join('hms_discharge_plans as p', 'p.admission_id', '=', 'a.id')
            ->where('a.company_id', $companyId)->where('a.patient_id', $patientId)->whereNull('a.deleted_at')
            ->whereNotNull('a.discharged_at')->where('a.discharged_at', '>=', now()->subDays(60))
            ->where(fn ($q) => $q->whereNotNull('p.home_prescriptions')->orWhereNotNull('p.home_care')->orWhereNotNull('p.follow_up_at')->orWhere('p.video_ids', '!=', '[]'))
            ->orderByDesc('a.discharged_at')
            ->first(['a.discharged_at', 'p.home_prescriptions', 'p.home_care', 'p.video_ids', 'p.follow_up_at']);
        if (! $row) {
            return null;
        }
        $ids = json_decode((string) $row->video_ids, true) ?: [];

        return [
            'discharged_at' => $row->discharged_at, 'prescriptions' => $row->home_prescriptions, 'home_care' => $row->home_care,
            'follow_up_at' => $row->follow_up_at,
            'videos' => DB::table('hms_care_videos')->where('company_id', $companyId)->whereIn('id', $ids ?: [0])->where('is_active', 1)
                ->orderBy('sort_order')->get(['title', 'url', 'topic']),
        ];
    }

    private function appointments(int $patientId, int $companyId)
    {
        return DB::table('appointments as a')->leftJoin('doctors as d', 'd.id', '=', 'a.doctor_id')
            ->leftJoin('hospital_departments as h', 'h.id', '=', 'a.department_id')
            ->where('a.company_id', $companyId)->where('a.patient_id', $patientId)->whereNull('a.deleted_at')
            ->whereIn('a.status', ['scheduled', 'confirmed'])->where('a.scheduled_at', '>=', now()->subHours(2))
            ->orderBy('a.scheduled_at')->limit(10)
            ->get(['a.reference', 'a.scheduled_at', 'a.status', 'a.pre_registered_at', 'd.name as doctor', 'd.specialty', 'h.name as department']);
    }
}
