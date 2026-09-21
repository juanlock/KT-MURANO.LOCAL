<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\Exam;
use App\Models\FeeStructure;
use App\Models\SchoolClass;
use App\Models\SchoolInvoice;
use App\Models\SchoolPayment;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimetableSlot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * School dashboard and the shared option lists every school form needs.
 *
 * One endpoint feeds the whole dashboard: the roll, attendance and fee figures
 * have to agree with each other, and separate calls would let the panels drift
 * apart mid-render.
 */
class SchoolDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'dashboard', Student::class);

        $today = Carbon::today();
        $year = AcademicYear::current();
        $yearId = $year ? $year->id : null;
        $monthStart = $today->copy()->startOfMonth();

        $enrolled = StudentEnrollment::whereNull('deleted_at')->where('status', 'active')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId));

        $byGender = Student::whereNull('deleted_at')->where('status', 'active')
            ->select('gender', DB::raw('count(*) as aggregate'))
            ->groupBy('gender')->pluck('aggregate', 'gender')->toArray();

        // Today's whole-day register.
        $todayMarks = StudentAttendance::whereNull('subject_id')
            ->whereDate('attendance_date', $today->toDateString())
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')->pluck('aggregate', 'status')->toArray();
        $todayTotal = array_sum($todayMarks);
        $todayPresent = 0;
        foreach (StudentAttendance::presentStatuses() as $status) {
            $todayPresent += (int) ($todayMarks[$status] ?? 0);
        }

        return response()->json([
            'academic_year' => $year ? ['id' => $year->id, 'name' => $year->name] : null,

            'students_total' => Student::whereNull('deleted_at')->where('status', 'active')->count(),
            'students_enrolled' => (clone $enrolled)->count(),
            'students_male' => (int) ($byGender['male'] ?? 0),
            'students_female' => (int) ($byGender['female'] ?? 0),
            'teachers_total' => Teacher::whereNull('deleted_at')->where('is_active', 1)->count(),
            'classes_total' => SchoolClass::whereNull('deleted_at')->where('is_active', 1)->count(),
            'sections_total' => ClassSection::whereNull('deleted_at')->count(),
            'subjects_total' => Subject::whereNull('deleted_at')->where('is_active', 1)->count(),

            'attendance_marked_today' => $todayTotal > 0,
            'present_today' => $todayPresent,
            'absent_today' => (int) ($todayMarks['absent'] ?? 0),
            'attendance_rate_today' => $todayTotal > 0 ? round(($todayPresent / $todayTotal) * 100, 1) : null,

            'fees_billed' => round((float) SchoolInvoice::whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')
                ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))->sum('total'), 2),
            'fees_collected' => round((float) SchoolInvoice::whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')
                ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))->sum('paid'), 2),
            'fees_outstanding' => round((float) SchoolInvoice::whereNull('deleted_at')
                ->whereIn('status', ['unpaid', 'partial'])
                ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
                ->selectRaw('COALESCE(SUM(total - paid),0) as due')->value('due'), 2),
            'fees_overdue_count' => SchoolInvoice::whereNull('deleted_at')
                ->whereIn('status', ['unpaid', 'partial'])
                ->whereNotNull('due_date')->whereDate('due_date', '<', $today->toDateString())->count(),
            'collected_month' => round((float) SchoolPayment::whereNull('deleted_at')
                ->whereDate('paid_on', '>=', $monthStart->toDateString())->sum('amount'), 2),

            'attendance_trend' => $this->attendanceTrend(),
            'by_class' => $this->byClass($yearId),
            'upcoming_exams' => $this->upcomingExams($today),
            'today_periods' => $this->todayPeriods($yearId, $today),
        ]);
    }

    /** Whole-school attendance rate for the last 14 days, oldest first. */
    private function attendanceTrend()
    {
        $from = Carbon::today()->subDays(13);

        $rows = StudentAttendance::whereNull('subject_id')
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->select('attendance_date', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('attendance_date', 'status')->get()
            ->groupBy(fn ($r) => substr((string) $r->attendance_date, 0, 10));

        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i)->toDateString();
            $group = $rows->get($day, collect());
            $present = (int) $group->whereIn('status', StudentAttendance::presentStatuses())->sum('aggregate');
            $total = (int) $group->sum('aggregate');

            $days[] = [
                'd' => $day,
                'present' => $present,
                'absent' => (int) $group->where('status', 'absent')->sum('aggregate'),
                'rate' => $total ? round(($present / $total) * 100, 1) : 0,
            ];
        }

        return $days;
    }

    private function byClass($yearId)
    {
        $counts = StudentEnrollment::whereNull('deleted_at')->where('status', 'active')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->select('class_id', DB::raw('count(*) as aggregate'))
            ->groupBy('class_id')->pluck('aggregate', 'class_id')->toArray();

        return SchoolClass::whereNull('deleted_at')->orderBy('level')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'students' => (int) ($counts[$c->id] ?? 0),
            ])
            ->filter(fn ($c) => $c['students'] > 0)
            ->values();
    }

    private function upcomingExams(Carbon $today)
    {
        return Exam::with('academicYear')->whereNull('deleted_at')
            ->whereIn('status', ['scheduled', 'ongoing'])
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $today->copy()->subDays(7)->toDateString())
            ->orderBy('start_date')->limit(5)->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'term' => $e->term,
                'start_date' => optional($e->start_date)->toDateString(),
                'status' => $e->status,
                'days_away' => $e->start_date ? $today->diffInDays(Carbon::parse($e->start_date), false) : null,
            ])->values();
    }

    /** What is being taught right now, for the "today" panel. */
    private function todayPeriods($yearId, Carbon $today)
    {
        $day = strtolower($today->format('D'));

        return TimetableSlot::with('subject', 'teacher', 'schoolClass', 'section')
            ->whereNull('deleted_at')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->where('day_of_week', $day)
            ->orderBy('start_time')->limit(10)->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'subject_name' => $s->subject ? $s->subject->name : '',
                'class_name' => $s->schoolClass ? $s->schoolClass->name : '',
                'section_name' => $s->section ? $s->section->name : null,
                'teacher_name' => $s->teacher ? $s->teacher->name : null,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
                'room' => $s->room,
            ])->values();
    }

    /**
     * Every select the school forms need, in one call. Students are NOT
     * included — a school can hold thousands, so those forms search instead.
     */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Student::class);

        return response()->json([
            'academic_years' => AcademicYear::whereNull('deleted_at')->orderBy('start_date', 'desc')
                ->get(['id', 'name', 'is_current'])
                ->map(fn ($y) => ['id' => $y->id, 'name' => $y->name, 'is_current' => (bool) $y->is_current])
                ->values(),
            'current_year_id' => optional(AcademicYear::current())->id,
            'classes' => SchoolClass::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('level')->orderBy('name')->get(['id', 'name', 'level']),
            'sections' => ClassSection::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'class_id', 'capacity']),
            'subjects' => Subject::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'class_id', 'type']),
            'teachers' => Teacher::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'specialization']),
            'fee_structures' => FeeStructure::whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'class_id', 'academic_year_id', 'amount', 'frequency'])
                ->map(fn ($f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'class_id' => $f->class_id,
                    'academic_year_id' => $f->academic_year_id,
                    'amount' => (float) $f->amount,
                    'frequency' => $f->frequency,
                ])->values(),
        ]);
    }

    /**
     * Type-ahead over students for every form that needs one. Capped at 20 — a
     * picker is for finding a known student, not browsing the roll.
     */
    public function searchStudents(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Student::class);

        $search = trim((string) $request->get('search', ''));
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $students = Student::whereNull('deleted_at')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('admission_number', 'LIKE', "%{$search}%")
                        ->orWhere('guardian_phone', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name')->limit(20)->get();

        $enrollments = StudentEnrollment::with('schoolClass', 'section')
            ->whereNull('deleted_at')
            ->whereIn('student_id', $students->pluck('id'))
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get()->keyBy('student_id');

        return response()->json([
            'students' => $students->map(function ($s) use ($enrollments) {
                $enrollment = $enrollments->get($s->id);
                $class = $enrollment ? $enrollment->label : null;

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'admission_number' => $s->admission_number,
                    'class_id' => $enrollment ? $enrollment->class_id : null,
                    'class_name' => $class,
                    'guardian_phone' => $s->guardian_phone,
                    'label' => $s->name . ' · ' . $s->admission_number . ($class ? ' · ' . $class : ''),
                ];
            })->values(),
        ]);
    }
}
