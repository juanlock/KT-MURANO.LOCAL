<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\HospitalInvoice;
use App\Models\HospitalPayment;
use App\Models\HospitalWard;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientVisit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hospital reports, all behind `hms_reports` (PatientPolicy@report) rather than
 * the individual module permissions — a manager reading revenue by department
 * has no business editing the fuel of a consultation.
 *
 * Each report answers { rows, totalRows, totals } so the Vue report shell's
 * export-everything refetch (limit=-1) works without special cases. Rows are
 * assembled in PHP: every one of these combines several tables with derived
 * figures that no single GROUP BY produces honestly.
 */
class HospitalReportController extends Controller
{
    /** Doctor workload: appointments, visits, patients seen, revenue. */
    public function doctors(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Patient::class);

        [$from, $to] = $this->range($request);

        $doctors = Doctor::with('department')->whereNull('deleted_at')
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->get();

        $appointments = $this->groupCount(Appointment::class, 'doctor_id', 'scheduled_at', $from, $to);
        $noShows = $this->groupCount(Appointment::class, 'doctor_id', 'scheduled_at', $from, $to, fn ($q) => $q->where('status', 'no_show'));
        $visitRows = PatientVisit::whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate('visit_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('visit_date', '<=', $to))
            ->select('doctor_id', DB::raw('count(*) as entries'), DB::raw('COUNT(DISTINCT patient_id) as patients'), DB::raw('COALESCE(SUM(fee),0) as fees'))
            ->groupBy('doctor_id')->get()->keyBy('doctor_id');

        $rows = $doctors->map(function ($d) use ($appointments, $noShows, $visitRows) {
            $booked = (int) ($appointments[$d->id] ?? 0);
            $missed = (int) ($noShows[$d->id] ?? 0);

            return [
                'id' => $d->id,
                'doctor_name' => $d->name,
                'specialty' => $d->specialty,
                'department_name' => $d->department ? $d->department->name : null,
                'appointments' => $booked,
                'no_shows' => $missed,
                'no_show_rate' => $booked > 0 ? round(($missed / $booked) * 100, 1) : null,
                'visits' => (int) ($visitRows[$d->id]->entries ?? 0),
                'patients' => (int) ($visitRows[$d->id]->patients ?? 0),
                'consultation_revenue' => round((float) ($visitRows[$d->id]->fees ?? 0), 2),
            ];
        });

        return $this->paginated($request, $rows, [
            'appointments' => $rows->sum('appointments'),
            'visits' => $rows->sum('visits'),
            'consultation_revenue' => round($rows->sum('consultation_revenue'), 2),
        ], 'visits');
    }

    /** Revenue by department: what was billed and what was actually collected. */
    public function revenue(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Patient::class);

        [$from, $to] = $this->range($request);

        // Billed money is attributed through the visit that produced it.
        $billed = DB::table('hospital_invoices')
            ->join('patient_visits', 'patient_visits.id', '=', 'hospital_invoices.visit_id')
            ->whereNull('hospital_invoices.deleted_at')
            ->where('hospital_invoices.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereDate('hospital_invoices.invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('hospital_invoices.invoice_date', '<=', $to))
            ->select('patient_visits.department_id', DB::raw('COALESCE(SUM(hospital_invoices.total),0) as billed'), DB::raw('COALESCE(SUM(hospital_invoices.paid),0) as collected'), DB::raw('COUNT(*) as invoices'))
            ->groupBy('patient_visits.department_id')
            ->get()->keyBy('department_id');

        $visits = PatientVisit::whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate('visit_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('visit_date', '<=', $to))
            ->select('department_id', DB::raw('count(*) as entries'), DB::raw('COALESCE(SUM(fee),0) as fees'))
            ->groupBy('department_id')->get()->keyBy('department_id');

        $departments = \App\Models\HospitalDepartment::whereNull('deleted_at')
            ->when($request->filled('department_id'), fn ($q) => $q->where('id', $request->department_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->get();

        $rows = $departments->map(function ($d) use ($billed, $visits) {
            $billedTotal = (float) ($billed[$d->id]->billed ?? 0);
            $collected = (float) ($billed[$d->id]->collected ?? 0);

            return [
                'id' => $d->id,
                'department_name' => $d->name,
                'visits' => (int) ($visits[$d->id]->entries ?? 0),
                'consultation_fees' => round((float) ($visits[$d->id]->fees ?? 0), 2),
                'invoices' => (int) ($billed[$d->id]->invoices ?? 0),
                'billed' => round($billedTotal, 2),
                'collected' => round($collected, 2),
                'outstanding' => round($billedTotal - $collected, 2),
                'collection_rate' => $billedTotal > 0 ? round(($collected / $billedTotal) * 100, 1) : null,
            ];
        });

        return $this->paginated($request, $rows, [
            'visits' => $rows->sum('visits'),
            'billed' => round($rows->sum('billed'), 2),
            'collected' => round($rows->sum('collected'), 2),
            'outstanding' => round($rows->sum('outstanding'), 2),
        ], 'billed');
    }

    /** Ward occupancy and inpatient throughput. */
    public function occupancy(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Patient::class);

        [$from, $to] = $this->range($request);

        $wards = HospitalWard::with('department')->whereNull('deleted_at')
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->get();

        $beds = DB::table('hospital_beds')->whereNull('deleted_at')
            ->select('ward_id', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('ward_id', 'status')->get();

        $admissions = Admission::whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate('admitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('admitted_at', '<=', $to))
            ->get()->groupBy('ward_id');

        $rows = $wards->map(function ($w) use ($beds, $admissions) {
            $wardBeds = $beds->where('ward_id', $w->id);
            $total = (int) $wardBeds->sum('aggregate');
            $occupied = (int) $wardBeds->where('status', 'occupied')->sum('aggregate');
            $stays = $admissions->get($w->id, collect());
            $closed = $stays->whereNotNull('discharged_at');

            return [
                'id' => $w->id,
                'ward_name' => $w->name,
                'type' => $w->type,
                'department_name' => $w->department ? $w->department->name : null,
                'beds' => $total,
                'occupied' => $occupied,
                'occupancy_rate' => $total > 0 ? round(($occupied / $total) * 100, 1) : null,
                'admissions' => $stays->count(),
                'discharges' => $closed->count(),
                // Average length of stay over CLOSED admissions only — including
                // open ones would drag the average down every time you refresh.
                'avg_stay' => $closed->count() ? round($closed->sum(fn ($a) => $a->nights) / $closed->count(), 1) : null,
                'bed_revenue' => round((float) $stays->sum(fn ($a) => $a->bed_charge), 2),
            ];
        });

        return $this->paginated($request, $rows, [
            'beds' => $rows->sum('beds'),
            'occupied' => $rows->sum('occupied'),
            'admissions' => $rows->sum('admissions'),
            'bed_revenue' => round($rows->sum('bed_revenue'), 2),
        ], 'occupancy_rate');
    }

    /** Patient activity: who is using the hospital, and what they owe. */
    public function patients(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Patient::class);

        [$from, $to] = $this->range($request);

        $visits = PatientVisit::whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate('visit_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('visit_date', '<=', $to))
            ->select('patient_id', DB::raw('count(*) as entries'), DB::raw('MAX(visit_date) as last_visit'))
            ->groupBy('patient_id')->get()->keyBy('patient_id');

        $invoices = HospitalInvoice::whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereDate('invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('invoice_date', '<=', $to))
            ->select('patient_id', DB::raw('COALESCE(SUM(total),0) as billed'), DB::raw('COALESCE(SUM(paid),0) as paid'))
            ->groupBy('patient_id')->get()->keyBy('patient_id');

        $admissions = $this->groupCount(Admission::class, 'patient_id', 'admitted_at', $from, $to);
        $labs = $this->groupCount(LabOrder::class, 'patient_id', 'ordered_at', $from, $to);

        $ids = collect($visits->keys())->merge($invoices->keys())->merge(array_keys($admissions))->merge(array_keys($labs))->unique();

        // Nothing in range: fall back to the register so the report is not blank.
        $query = Patient::whereNull('deleted_at')
            ->when($ids->isNotEmpty(), fn ($q) => $q->whereIn('id', $ids))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($inner) use ($request) {
                    $inner->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('mrn', 'LIKE', "%{$request->search}%");
                });
            });

        $rows = $query->get()->map(function ($p) use ($visits, $invoices, $admissions, $labs) {
            $billed = (float) ($invoices[$p->id]->billed ?? 0);
            $paid = (float) ($invoices[$p->id]->paid ?? 0);

            return [
                'id' => $p->id,
                'mrn' => $p->mrn,
                'patient_name' => $p->name,
                'gender' => $p->gender,
                'age' => $p->age,
                'visits' => (int) ($visits[$p->id]->entries ?? 0),
                'admissions' => (int) ($admissions[$p->id] ?? 0),
                'lab_orders' => (int) ($labs[$p->id] ?? 0),
                'billed' => round($billed, 2),
                'paid' => round($paid, 2),
                'outstanding' => round($billed - $paid, 2),
                'last_visit' => $visits[$p->id]->last_visit ?? null,
            ];
        });

        return $this->paginated($request, $rows, [
            'visits' => $rows->sum('visits'),
            'admissions' => $rows->sum('admissions'),
            'billed' => round($rows->sum('billed'), 2),
            'outstanding' => round($rows->sum('outstanding'), 2),
        ], 'visits');
    }

    /** Collections by payment method. */
    public function collections(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Patient::class);

        [$from, $to] = $this->range($request);

        $rows = HospitalPayment::whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate('paid_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('paid_on', '<=', $to))
            ->select('method', DB::raw('count(*) as entries'), DB::raw('COALESCE(SUM(amount),0) as amount'))
            ->groupBy('method')->get()
            ->map(fn ($r) => [
                'id' => $r->method,
                'method' => $r->method ?: 'unknown',
                'payments' => (int) $r->entries,
                'amount' => round((float) $r->amount, 2),
            ]);

        $total = $rows->sum('amount');
        $rows = $rows->map(fn ($r) => $r + [
            'share' => $total > 0 ? round(($r['amount'] / $total) * 100, 1) : 0,
        ]);

        return $this->paginated($request, $rows, [
            'payments' => $rows->sum('payments'),
            'amount' => round($total, 2),
        ], 'amount');
    }

    // ------------------------------------------------------------------
    // Shared pieces
    // ------------------------------------------------------------------

    private function range(Request $request)
    {
        return [
            $request->filled('start_date') ? $request->start_date : null,
            $request->filled('end_date') ? $request->end_date : null,
        ];
    }

    /** COUNT(*) grouped by one column, as [id => count]. */
    private function groupCount($model, $column, $dateColumn, $from, $to, $extra = null)
    {
        $query = $model::whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate($dateColumn, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($dateColumn, '<=', $to));

        if ($extra) {
            $extra($query);
        }

        return $query->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)->pluck('aggregate', $column)->toArray();
    }

    /**
     * Sort + page an assembled collection. limit=-1 returns everything, which
     * is what the report shell's export uses. Sorting happens here rather than
     * in SQL because these rows are computed, and ORDER BY on a column MySQL
     * never selected is a 1054.
     */
    private function paginated(Request $request, $rows, array $totals, $defaultSort)
    {
        $sortField = $request->SortField ?: $defaultSort;
        $descending = strtolower((string) ($request->SortType ?: 'desc')) !== 'asc';

        if ($rows->count() && array_key_exists($sortField, $rows->first())) {
            $rows = $descending
                ? $rows->sortByDesc($sortField, SORT_NATURAL | SORT_FLAG_CASE)
                : $rows->sortBy($sortField, SORT_NATURAL | SORT_FLAG_CASE);
        }
        $rows = $rows->values();

        $totalRows = $rows->count();
        $perPage = (int) ($request->limit ?? 10);
        if ($perPage === -1) {
            return response()->json(['rows' => $rows, 'totalRows' => $totalRows, 'totals' => $totals]);
        }

        $page = max(1, (int) $request->get('page', 1));

        return response()->json([
            'rows' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'totalRows' => $totalRows,
            'totals' => $totals,
        ]);
    }
}
