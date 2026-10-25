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
            'patients' => $patients->map(fn ($p) => [
                'name' => $p->name, 'mrn' => $p->mrn,
                'stay' => $this->stay($p->id, $companyId),
                'discharge' => $this->discharge($p->id, $companyId),
                'appointments' => $this->appointments($p->id, $companyId),
            ])->values(),
        ]);
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
            ->get(['a.reference', 'a.scheduled_at', 'a.status', 'd.name as doctor', 'h.name as department']);
    }
}
