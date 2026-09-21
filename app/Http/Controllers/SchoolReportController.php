<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\SchoolClass;
use App\Models\SchoolInvoice;
use App\Models\SchoolPayment;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use App\Models\TimetableSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * School reports, all behind `school_reports` (StudentPolicy@report) rather than
 * the individual module permissions — a head reading the fee-defaulter list has
 * no business editing the attendance register.
 *
 * Each report answers { rows, totalRows, totals } so the report shell's
 * export-everything refetch (limit=-1) works without special cases. Rows are
 * assembled in PHP because every one of these combines several tables with
 * derived figures no single GROUP BY produces honestly.
 */
class SchoolReportController extends Controller
{
    /** Enrolment by class: headcount, gender split, capacity. */
    public function enrollment(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Student::class);

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $enrollments = StudentEnrollment::with('student')
            ->whereNull('deleted_at')->where('status', 'active')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get()->groupBy('class_id');

        $capacity = ClassSection::whereNull('deleted_at')
            ->select('class_id', DB::raw('SUM(capacity) as seats'), DB::raw('COUNT(*) as sections'))
            ->groupBy('class_id')->get()->keyBy('class_id');

        $rows = SchoolClass::whereNull('deleted_at')
            ->when($request->filled('class_id'), fn ($q) => $q->where('id', $request->class_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->orderBy('level')->get()
            ->map(function ($class) use ($enrollments, $capacity) {
                $mine = $enrollments->get($class->id, collect());
                $students = $mine->filter(fn ($e) => $e->student !== null);
                $seats = (int) ($capacity[$class->id]->seats ?? 0);
                $total = $students->count();

                return [
                    'id' => $class->id,
                    'class_name' => $class->name,
                    'level' => (int) $class->level,
                    'sections' => (int) ($capacity[$class->id]->sections ?? 0),
                    'students' => $total,
                    'male' => $students->filter(fn ($e) => $e->student->gender === 'male')->count(),
                    'female' => $students->filter(fn ($e) => $e->student->gender === 'female')->count(),
                    'capacity' => $seats ?: null,
                    'utilisation' => $seats > 0 ? round(($total / $seats) * 100, 1) : null,
                ];
            });

        return $this->paginated($request, $rows, [
            'students' => $rows->sum('students'),
            'male' => $rows->sum('male'),
            'female' => $rows->sum('female'),
        ], 'students');
    }

    /** Attendance rate per class over a date range. */
    public function attendance(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Student::class);

        [$from, $to] = $this->range($request);

        $marks = StudentAttendance::whereNull('subject_id')
            ->when($from, fn ($q) => $q->whereDate('attendance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attendance_date', '<=', $to))
            ->select('class_id', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('class_id', 'status')->get()->groupBy('class_id');

        $rows = SchoolClass::whereNull('deleted_at')
            ->when($request->filled('class_id'), fn ($q) => $q->where('id', $request->class_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->orderBy('level')->get()
            ->map(function ($class) use ($marks) {
                $mine = $marks->get($class->id, collect());
                $present = (int) $mine->whereIn('status', StudentAttendance::presentStatuses())->sum('aggregate');
                $total = (int) $mine->sum('aggregate');

                return [
                    'id' => $class->id,
                    'class_name' => $class->name,
                    'marked_days' => $total,
                    'present' => $present,
                    'absent' => (int) $mine->where('status', 'absent')->sum('aggregate'),
                    'late' => (int) $mine->where('status', 'late')->sum('aggregate'),
                    'excused' => (int) $mine->where('status', 'excused')->sum('aggregate'),
                    'rate' => $total > 0 ? round(($present / $total) * 100, 1) : null,
                ];
            });

        return $this->paginated($request, $rows, [
            'present' => $rows->sum('present'),
            'absent' => $rows->sum('absent'),
            'marked_days' => $rows->sum('marked_days'),
        ], 'rate');
    }

    /**
     * Students whose attendance has fallen below a threshold — the list a head
     * of year actually acts on. Defaults to 75%.
     */
    public function absentees(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Student::class);

        [$from, $to] = $this->range($request);
        $threshold = (float) ($request->threshold ?: 75);
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $marks = StudentAttendance::whereNull('subject_id')
            ->when($from, fn ($q) => $q->whereDate('attendance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attendance_date', '<=', $to))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->select('student_id', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('student_id', 'status')->get()->groupBy('student_id');

        if ($marks->isEmpty()) {
            return $this->paginated($request, collect(), ['students' => 0], 'rate');
        }

        $students = Student::whereNull('deleted_at')->whereIn('id', $marks->keys())
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($inner) use ($request) {
                    $inner->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('admission_number', 'LIKE', "%{$request->search}%");
                });
            })->get()->keyBy('id');

        $enrollments = StudentEnrollment::with('schoolClass', 'section')
            ->whereNull('deleted_at')->whereIn('student_id', $students->keys())
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get()->keyBy('student_id');

        $rows = collect();
        foreach ($marks as $studentId => $group) {
            $student = $students->get($studentId);
            if (! $student) {
                continue;
            }

            $present = (int) $group->whereIn('status', StudentAttendance::presentStatuses())->sum('aggregate');
            $total = (int) $group->sum('aggregate');
            $rate = $total > 0 ? round(($present / $total) * 100, 1) : null;

            if ($rate === null || $rate >= $threshold) {
                continue;
            }

            $enrollment = $enrollments->get($studentId);
            $rows->push([
                'id' => $student->id,
                'student_name' => $student->name,
                'admission_number' => $student->admission_number,
                'class_name' => $enrollment && $enrollment->schoolClass ? $enrollment->schoolClass->name : null,
                'section_name' => $enrollment && $enrollment->section ? $enrollment->section->name : null,
                'guardian_phone' => $student->guardian_phone,
                'marked_days' => $total,
                'present' => $present,
                'absent' => (int) $group->where('status', 'absent')->sum('aggregate'),
                'rate' => $rate,
            ]);
        }

        return $this->paginated($request, $rows->values(), [
            'students' => $rows->count(),
            'absent' => $rows->sum('absent'),
        ], 'rate', false);
    }

    /** Exam performance per class: average, pass rate, best and worst. */
    public function performance(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Student::class);

        $exam = $request->filled('exam_id')
            ? Exam::find($request->exam_id)
            : Exam::whereNull('deleted_at')->orderBy('start_date', 'desc')->first();

        if (! $exam) {
            return $this->paginated($request, collect(), ['students' => 0], 'average');
        }

        $papers = ExamSubject::with('subject', 'schoolClass')->where('exam_id', $exam->id)
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->get();

        $results = ExamResult::whereIn('exam_subject_id', $papers->pluck('id'))->get()
            ->groupBy('exam_subject_id');

        $rows = $papers->map(function ($paper) use ($results) {
            $mine = $results->get($paper->id, collect());
            // Absentees are excluded from the average; a null mark is not a zero.
            $sat = $mine->filter(fn ($r) => $r->marks !== null);
            $max = (float) $paper->max_marks;
            $average = $sat->count() ? round($sat->avg(fn ($r) => (float) $r->marks), 2) : null;

            return [
                'id' => $paper->id,
                'class_name' => $paper->schoolClass ? $paper->schoolClass->name : '',
                'subject_name' => $paper->subject ? $paper->subject->name : '',
                'max_marks' => $max,
                'entered' => $sat->count(),
                'absent' => $mine->filter(fn ($r) => (bool) $r->is_absent)->count(),
                'average' => $average,
                'average_pct' => ($average !== null && $max > 0) ? round(($average / $max) * 100, 1) : null,
                'highest' => $sat->count() ? round((float) $sat->max(fn ($r) => (float) $r->marks), 2) : null,
                'lowest' => $sat->count() ? round((float) $sat->min(fn ($r) => (float) $r->marks), 2) : null,
                'passed' => $sat->filter(fn ($r) => (float) $r->marks >= (float) $paper->pass_marks)->count(),
                'pass_rate' => $sat->count()
                    ? round(($sat->filter(fn ($r) => (float) $r->marks >= (float) $paper->pass_marks)->count() / $sat->count()) * 100, 1)
                    : null,
            ];
        });

        return $this->paginated($request, $rows, [
            'entered' => $rows->sum('entered'),
            'passed' => $rows->sum('passed'),
        ], 'average_pct');
    }

    /** Fee collection per class: billed, collected, outstanding. */
    public function fees(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Student::class);

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;
        [$from, $to] = $this->range($request);

        $invoices = SchoolInvoice::whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->when($from, fn ($q) => $q->whereDate('invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('invoice_date', '<=', $to))
            ->select('class_id', DB::raw('COUNT(*) as invoices'), DB::raw('COALESCE(SUM(total),0) as billed'), DB::raw('COALESCE(SUM(paid),0) as collected'))
            ->groupBy('class_id')->get()->keyBy('class_id');

        $rows = SchoolClass::whereNull('deleted_at')
            ->when($request->filled('class_id'), fn ($q) => $q->where('id', $request->class_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->orderBy('level')->get()
            ->map(function ($class) use ($invoices) {
                $billed = (float) ($invoices[$class->id]->billed ?? 0);
                $collected = (float) ($invoices[$class->id]->collected ?? 0);

                return [
                    'id' => $class->id,
                    'class_name' => $class->name,
                    'invoices' => (int) ($invoices[$class->id]->invoices ?? 0),
                    'billed' => round($billed, 2),
                    'collected' => round($collected, 2),
                    'outstanding' => round($billed - $collected, 2),
                    'collection_rate' => $billed > 0 ? round(($collected / $billed) * 100, 1) : null,
                ];
            });

        return $this->paginated($request, $rows, [
            'billed' => round($rows->sum('billed'), 2),
            'collected' => round($rows->sum('collected'), 2),
            'outstanding' => round($rows->sum('outstanding'), 2),
        ], 'outstanding');
    }

    /** Families who owe money, worst first — the bursar's chase list. */
    public function defaulters(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Student::class);

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $owed = SchoolInvoice::whereNull('deleted_at')
            ->whereIn('status', ['unpaid', 'partial'])
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->select(
                'student_id',
                DB::raw('COUNT(*) as invoices'),
                // NOT aliased `due`: SchoolInvoice has a getDueAttribute()
                // accessor, and on a hydrated model the accessor shadows the
                // aliased column — which silently reported every debtor as
                // owing nothing, because the accessor's inputs are not selected
                // in a grouped query.
                DB::raw('COALESCE(SUM(total - paid),0) as outstanding_amount'),
                DB::raw('MIN(due_date) as earliest_due')
            )
            ->groupBy('student_id')->get()->keyBy('student_id');

        if ($owed->isEmpty()) {
            return $this->paginated($request, collect(), ['due' => 0, 'students' => 0], 'due');
        }

        $students = Student::whereNull('deleted_at')->whereIn('id', $owed->keys())
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($inner) use ($request) {
                    $inner->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('admission_number', 'LIKE', "%{$request->search}%")
                        ->orWhere('guardian_name', 'LIKE', "%{$request->search}%");
                });
            })->get();

        $enrollments = StudentEnrollment::with('schoolClass', 'section')
            ->whereNull('deleted_at')->whereIn('student_id', $students->pluck('id'))
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get()->keyBy('student_id');

        $today = now()->startOfDay();

        $rows = $students->map(function ($s) use ($owed, $enrollments, $today) {
            $record = $owed->get($s->id);
            $enrollment = $enrollments->get($s->id);
            $earliest = $record->earliest_due ? \Carbon\Carbon::parse($record->earliest_due) : null;

            return [
                'id' => $s->id,
                'student_name' => $s->name,
                'admission_number' => $s->admission_number,
                'class_name' => $enrollment && $enrollment->schoolClass ? $enrollment->schoolClass->name : null,
                'section_name' => $enrollment && $enrollment->section ? $enrollment->section->name : null,
                'guardian_name' => $s->guardian_name,
                'guardian_phone' => $s->guardian_phone,
                'invoices' => (int) $record->invoices,
                'due' => round((float) $record->outstanding_amount, 2),
                'earliest_due' => $earliest ? $earliest->toDateString() : null,
                // Negative = overdue by that many days; the chase list sorts on it.
                'days_overdue' => $earliest && $earliest->lt($today) ? $today->diffInDays($earliest) : 0,
            ];
        })->values();

        return $this->paginated($request, $rows, [
            'students' => $rows->count(),
            'due' => round($rows->sum('due'), 2),
        ], 'due');
    }

    /** Teacher workload: periods a week, subjects and form classes. */
    public function teachers(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Student::class);

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $slots = TimetableSlot::whereNull('deleted_at')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get()->groupBy('teacher_id');

        $formClasses = ClassSection::whereNull('deleted_at')
            ->select('teacher_id', DB::raw('count(*) as aggregate'))
            ->groupBy('teacher_id')->pluck('aggregate', 'teacher_id')->toArray();

        $rows = Teacher::whereNull('deleted_at')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->get()
            ->map(function ($t) use ($slots, $formClasses) {
                $mine = $slots->get($t->id, collect());

                return [
                    'id' => $t->id,
                    'teacher_name' => $t->name,
                    'specialization' => $t->specialization,
                    'is_active' => (bool) $t->is_active,
                    'weekly_periods' => $mine->count(),
                    'subjects' => $mine->pluck('subject_id')->unique()->filter()->count(),
                    'classes' => $mine->pluck('class_id')->unique()->filter()->count(),
                    'form_classes' => (int) ($formClasses[$t->id] ?? 0),
                    'busiest_day' => $mine->count()
                        ? $mine->groupBy('day_of_week')->sortByDesc(fn ($g) => $g->count())->keys()->first()
                        : null,
                ];
            });

        return $this->paginated($request, $rows, [
            'weekly_periods' => $rows->sum('weekly_periods'),
        ], 'weekly_periods');
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

    /**
     * Sort + page an assembled collection. limit=-1 returns everything, which is
     * what the report shell's export uses. Sorting happens here because these
     * rows are computed, and ORDER BY on a column MySQL never selected is a 1054.
     *
     * `$descendingDefault` is false for reports where the interesting end is the
     * BOTTOM (worst attendance first).
     */
    private function paginated(Request $request, $rows, array $totals, $defaultSort, $descendingDefault = true)
    {
        $sortField = $request->SortField ?: $defaultSort;
        $descending = $request->filled('SortType')
            ? strtolower((string) $request->SortType) !== 'asc'
            : $descendingDefault;

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
