<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\HospitalBed;
use App\Models\HospitalDepartment;
use App\Models\HospitalInvoice;
use App\Models\HospitalPayment;
use App\Models\HospitalWard;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hospital dashboard and the shared option lists every hospital form needs.
 *
 * One endpoint feeds the whole dashboard: the census, occupancy, today's
 * activity and revenue have to agree with each other, and six separate calls
 * would let the panels drift apart mid-render.
 */
class HospitalDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'dashboard', Patient::class);

        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $bedsByStatus = HospitalBed::whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')->pluck('aggregate', 'status')->toArray();

        $totalBeds = array_sum($bedsByStatus);
        $occupied = (int) ($bedsByStatus['occupied'] ?? 0);

        return response()->json([
            // Census
            'patients_total' => Patient::whereNull('deleted_at')->count(),
            'patients_new_today' => Patient::whereNull('deleted_at')->whereDate('created_at', $today->toDateString())->count(),
            'doctors_active' => Doctor::whereNull('deleted_at')->where('is_active', 1)->count(),
            'departments' => HospitalDepartment::whereNull('deleted_at')->count(),

            // Today
            'appointments_today' => Appointment::whereNull('deleted_at')
                ->whereDate('scheduled_at', $today->toDateString())->count(),
            'appointments_pending' => Appointment::whereNull('deleted_at')
                ->whereDate('scheduled_at', $today->toDateString())
                ->whereIn('status', Appointment::blockingStatuses())->count(),
            'visits_today' => PatientVisit::whereNull('deleted_at')
                ->whereDate('visit_date', $today->toDateString())->count(),
            'admissions_current' => Admission::whereNull('deleted_at')->where('status', 'admitted')->count(),
            'admissions_today' => Admission::whereNull('deleted_at')
                ->whereDate('admitted_at', $today->toDateString())->count(),
            'discharges_today' => Admission::whereNull('deleted_at')
                ->whereDate('discharged_at', $today->toDateString())->count(),

            // Beds
            'beds_total' => $totalBeds,
            'beds_occupied' => $occupied,
            'beds_available' => (int) ($bedsByStatus['available'] ?? 0),
            'occupancy_rate' => $totalBeds > 0 ? round(($occupied / $totalBeds) * 100, 1) : 0,

            // Lab
            'lab_pending' => LabOrder::whereNull('deleted_at')
                ->whereIn('status', ['ordered', 'sample_collected', 'in_progress'])->count(),
            'lab_critical' => DB::table('lab_order_items')
                ->join('lab_orders', 'lab_orders.id', '=', 'lab_order_items.lab_order_id')
                ->whereNull('lab_orders.deleted_at')
                ->where('lab_order_items.flag', 'critical')
                ->whereDate('lab_orders.ordered_at', '>=', $today->copy()->subDays(7)->toDateString())
                ->count(),

            // Money
            'revenue_today' => round((float) HospitalPayment::whereNull('deleted_at')
                ->whereDate('paid_on', $today->toDateString())->sum('amount'), 2),
            'revenue_month' => round((float) HospitalPayment::whereNull('deleted_at')
                ->whereDate('paid_on', '>=', $monthStart->toDateString())->sum('amount'), 2),
            'outstanding' => round((float) HospitalInvoice::whereNull('deleted_at')
                ->whereIn('status', ['unpaid', 'partial'])
                ->selectRaw('COALESCE(SUM(total - paid),0) as due')->value('due'), 2),

            'trend' => $this->trend(),
            'by_department' => $this->byDepartment(),
            'upcoming' => $this->upcoming($today),
            'ward_occupancy' => $this->wardOccupancy(),
        ]);
    }

    /** Visits and revenue for the last 14 days, oldest first. */
    private function trend()
    {
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $days[] = [
                'd' => $day->toDateString(),
                'visits' => PatientVisit::whereNull('deleted_at')->whereDate('visit_date', $day->toDateString())->count(),
                'admissions' => Admission::whereNull('deleted_at')->whereDate('admitted_at', $day->toDateString())->count(),
                'revenue' => round((float) HospitalPayment::whereNull('deleted_at')
                    ->whereDate('paid_on', $day->toDateString())->sum('amount'), 2),
            ];
        }

        return $days;
    }

    private function byDepartment()
    {
        $counts = PatientVisit::whereNull('deleted_at')
            ->whereDate('visit_date', '>=', Carbon::today()->subDays(30)->toDateString())
            ->whereNotNull('department_id')
            ->select('department_id', DB::raw('count(*) as aggregate'))
            ->groupBy('department_id')->pluck('aggregate', 'department_id')->toArray();

        return HospitalDepartment::whereNull('deleted_at')->whereIn('id', array_keys($counts))->get()
            ->map(fn ($d) => ['name' => $d->name, 'visits' => (int) ($counts[$d->id] ?? 0)])
            ->sortByDesc('visits')->values();
    }

    /** The next few appointments still expected today. */
    private function upcoming(Carbon $today)
    {
        return Appointment::with('patient', 'doctor')->whereNull('deleted_at')
            ->whereDate('scheduled_at', $today->toDateString())
            ->whereIn('status', Appointment::blockingStatuses())
            ->orderBy('scheduled_at')
            ->limit(8)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'reference' => $a->reference,
                'scheduled_at' => optional($a->scheduled_at)->toIso8601String(),
                'patient_name' => $a->patient ? $a->patient->name : '',
                'patient_id' => $a->patient_id,
                'doctor_name' => $a->doctor ? $a->doctor->name : '',
                'type' => $a->type,
                'status' => $a->status,
            ])->values();
    }

    private function wardOccupancy()
    {
        $wards = HospitalWard::whereNull('deleted_at')->orderBy('name')->get();
        $beds = HospitalBed::whereNull('deleted_at')
            ->select('ward_id', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('ward_id', 'status')->get();

        return $wards->map(function ($ward) use ($beds) {
            $rows = $beds->where('ward_id', $ward->id);
            $total = (int) $rows->sum('aggregate');
            $occupied = (int) $rows->where('status', 'occupied')->sum('aggregate');

            return [
                'id' => $ward->id,
                'name' => $ward->name,
                'type' => $ward->type,
                'total' => $total,
                'occupied' => $occupied,
                'rate' => $total > 0 ? round(($occupied / $total) * 100) : 0,
            ];
        })->filter(fn ($w) => $w['total'] > 0)->values();
    }

    /**
     * Every select the hospital forms need, in one call. Patients are NOT
     * included — there can be tens of thousands, so those forms search the
     * patient endpoint instead of loading a dropdown.
     */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Patient::class);

        return response()->json([
            'departments' => HospitalDepartment::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name']),
            'doctors' => Doctor::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')
                ->get(['id', 'name', 'department_id', 'specialty', 'consultation_fee'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'department_id' => $d->department_id,
                    'specialty' => $d->specialty,
                    'consultation_fee' => (float) $d->consultation_fee,
                ])->values(),
            'wards' => HospitalWard::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'department_id', 'daily_rate'])
                ->map(fn ($w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'department_id' => $w->department_id,
                    'daily_rate' => (float) $w->daily_rate,
                ])->values(),
            'lab_tests' => LabTest::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'code', 'category', 'price', 'unit', 'normal_range'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'code' => $t->code,
                    'category' => $t->category,
                    'price' => (float) $t->price,
                ])->values(),
        ]);
    }

    /** Free beds for the admission form, grouped by ward. */
    public function availableBeds(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Admission::class);

        $beds = HospitalBed::with('ward')->whereNull('deleted_at')
            ->where('status', 'available')
            ->when($request->filled('ward_id'), fn ($q) => $q->where('ward_id', $request->ward_id))
            ->orderBy('ward_id')->orderBy('bed_number')->get();

        return response()->json([
            'beds' => $beds->map(fn ($b) => [
                'id' => $b->id,
                'bed_number' => $b->bed_number,
                'ward_id' => $b->ward_id,
                'ward_name' => $b->ward ? $b->ward->name : '',
                'daily_rate' => $b->effective_rate,
                'label' => ($b->ward ? $b->ward->name . ' — ' : '') . $b->bed_number,
            ])->values(),
        ]);
    }

    /**
     * Type-ahead over patients for every form that needs one. Capped at 20 —
     * a picker is for finding a known patient, not browsing the register.
     */
    public function searchPatients(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Patient::class);

        $search = trim((string) $request->get('search', ''));

        $patients = Patient::whereNull('deleted_at')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('mrn', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name')->limit(20)->get();

        return response()->json([
            'patients' => $patients->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'mrn' => $p->mrn,
                'phone' => $p->phone,
                'gender' => $p->gender,
                'age' => $p->age,
                'label' => $p->name . ' · ' . $p->mrn,
            ])->values(),
        ]);
    }

    /**
     * Type-ahead over the pharmacy catalogue, so a prescription line can point
     * at real stock instead of a re-typed drug name.
     */
    public function searchMedicines(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Patient::class);

        $search = trim((string) $request->get('search', ''));

        $products = Product::whereNull('deleted_at')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('code', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name')->limit(20)->get(['id', 'name', 'code', 'price']);

        return response()->json([
            'medicines' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'price' => (float) $p->price,
            ])->values(),
        ]);
    }
}
