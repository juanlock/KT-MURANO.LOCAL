<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\ExamResult;
use App\Models\SchoolClass;
use App\Models\SchoolInvoice;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentEnrollment;
use App\Support\CompanyFiles;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Student register and enrolment.
 *
 * A student is never "in a class" directly — they hold an ENROLMENT in a
 * class+section for an academic year. That is what lets last year's register,
 * results and bills survive this year's promotion instead of being overwritten.
 *
 * Photos go to public/images/students, the convention the rest of the app's
 * uploads follow.
 */
class SchoolStudentController extends Controller
{
    use ScopesToCompany;

    private const SORTABLE = ['id', 'admission_number', 'name', 'gender', 'date_of_birth', 'admission_date', 'status', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Student::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'name';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $query = Student::whereNull('deleted_at')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }
        // Class/section filters run through the enrolment for the chosen year.
        if ($request->filled('class_id') || $request->filled('section_id') || $request->boolean('unassigned')) {
            $enrollmentIds = StudentEnrollment::whereNull('deleted_at')
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
                ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
                ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
                ->pluck('student_id');

            if ($request->boolean('unassigned')) {
                $query->whereNotIn('id', $enrollmentIds);
            } else {
                $query->whereIn('id', $enrollmentIds);
            }
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('admission_number', 'LIKE', "%{$search}%")
                    ->orWhere('guardian_name', 'LIKE', "%{$search}%")
                    ->orWhere('guardian_phone', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        // Performance (2026-10-21): the year's weighted average and each term's average.
        $perf = $this->performance($companyId, $yearId ? (int) $yearId : null);
        if ($request->SortField === 'performance') {
            // Best first (no marks last), then by name; paginated after sorting.
            $ids = (clone $query)->orderBy('name')->pluck('id')->all();
            usort($ids, function ($a, $b) use ($perf, $dir) {
                $x = $perf['students'][$a]['average'] ?? null;
                $y = $perf['students'][$b]['average'] ?? null;
                if ($x === $y) {
                    return 0;
                }
                if ($x === null || $y === null) {
                    return $x === null ? 1 : -1;
                }

                return $dir === 'asc' ? $x <=> $y : $y <=> $x;
            });
            $pageIds = array_slice($ids, $offset, $perPage);
            $students = Student::whereIn('id', $pageIds)->get()->sortBy(fn ($s) => array_search($s->id, $pageIds))->values();
        } else {
            $students = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();
        }

        // Current enrolments in one query rather than one per row.
        $enrollments = StudentEnrollment::with('schoolClass', 'section')
            ->whereNull('deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('student_id', $students->pluck('id'))
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get()->keyBy('student_id');

        // superadmin/AdminSystem: every company (2026-10-21) -- each row says whose it is.
        $companyNames = \App\Models\Company::whereIn('id', $students->pluck('company_id')->unique())->pluck('name', 'id');

        return response()->json([
            'totalRows' => $totalRows,
            'students' => $students->map(fn ($s) => $this->present($s, $enrollments->get($s->id)) + [
                'company_name' => $s->company_id == \App\Models\Setting::TEMPLATE_COMPANY_ID ? 'AdminSystem' : ($companyNames[$s->company_id] ?? null),
                'performance' => $perf['students'][$s->id]['average'] ?? null,
                'performance_terms' => $perf['students'][$s->id]['terms'] ?? [],
            ])->values(),
            'terms' => $perf['terms'],
            'pass_mark' => $perf['pass'],
        ]);
    }

    /**
     * Every student's marks for a year (2026-10-21), the same way the report
     * card counts them: each subject's average over the terms (rounded to a
     * whole mark when out of 20), then the credit-weighted average; plus each
     * term's plain average. ['students' => [id => [average, terms => [exam_id => avg]]], 'terms', 'pass', 'max'].
     */
    private function performance(?int $companyId, ?int $yearId): array
    {
        $out = ['students' => [], 'terms' => [], 'pass' => null, 'max' => null];
        if (! $yearId) {
            return $out;
        }
        $rows = DB::table('exam_results as r')
            ->join('exam_subjects as es', 'es.id', '=', 'r.exam_subject_id')
            ->join('exams as e', 'e.id', '=', 'es.exam_id')
            ->leftJoin('subjects as sb', 'sb.id', '=', 'es.subject_id')
            ->when($companyId !== null, fn ($q) => $q->where('r.company_id', $companyId))->where('e.academic_year_id', $yearId)->whereNull('e.deleted_at')
            ->whereNotNull('r.marks')
            ->get(['r.student_id', 'es.exam_id', 'es.subject_id', 'sb.credit', 'r.marks', 'es.max_marks', 'es.pass_marks']);
        if ($rows->isEmpty()) {
            return $out;
        }
        $max = (float) $rows->max('max_marks');
        $out['max'] = $max;
        $out['pass'] = (float) $rows->max('pass_marks');
        $whole = $max === 20.0;
        $roman = ['term_1' => 'I', 'term_2' => 'II', 'term_3' => 'III', 'term_4' => 'IV'];
        $out['terms'] = \App\Models\Exam::whereIn('id', $rows->pluck('exam_id')->unique())->orderBy('start_date')
            ->get(['id', 'name', 'term'])->map(fn ($e) => ['id' => $e->id, 'term' => $e->term, 'label' => $roman[$e->term] ?? $e->name])->values()->all();

        foreach ($rows->groupBy('student_id') as $sid => $mine) {
            $sum = $credits = 0;
            foreach ($mine->groupBy('subject_id') as $subRows) {
                $avg = $subRows->avg('marks');
                $avg = $whole ? floor($avg + 0.5) : $avg;
                $credit = (float) ($subRows->first()->credit ?: 1);
                $sum += $avg * $credit;
                $credits += $credit;
            }
            $out['students'][$sid] = [
                'average' => $credits ? round($sum / $credits, 2) : null,
                'terms' => $mine->groupBy('exam_id')->map(fn ($g) => round($g->avg('marks'), 2))->all(),
            ];
        }

        return $out;
    }

    /**
     * Cards above the student list (2026-10-21): best student (and the top 3),
     * best class and section by average, the school average, students below the
     * pass mark, and the best / worst attendance -- for the year and company.
     */
    public function highlights(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Student::class);
        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;
        $perf = $this->performance($companyId, $yearId ? (int) $yearId : null);
        $avgs = collect($perf['students'])->map(fn ($p) => $p['average'])->filter(fn ($v) => $v !== null);

        $enrol = StudentEnrollment::with('schoolClass', 'section')->whereNull('deleted_at')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))->where('status', 'active')->get()->keyBy('student_id');
        $names = Student::whereIn('id', $enrol->keys())->pluck('name', 'id');
        $place = fn ($sid) => $enrol->get($sid) ? trim(optional($enrol[$sid]->schoolClass)->name.' '.optional($enrol[$sid]->section)->name) : '';

        $top = $avgs->sortDesc()->take(3)->map(fn ($a, $sid) => ['id' => $sid, 'name' => $names[$sid] ?? '', 'place' => $place($sid), 'average' => $a])->values();
        $group = function (callable $key, callable $label) use ($avgs, $enrol) {
            return $avgs->filter(fn ($a, $sid) => $enrol->has($sid))->groupBy(fn ($a, $sid) => $key($enrol[$sid]), true)
                ->map(fn ($g, $k) => ['label' => $label($enrol[$g->keys()->first()]), 'average' => round($g->avg(), 2), 'students' => $g->count()])
                ->sortByDesc('average')->values();
        };
        $classes = $group(fn ($e) => $e->class_id, fn ($e) => optional($e->schoolClass)->name);
        $sections = $group(fn ($e) => $e->class_id.'-'.$e->section_id, fn ($e) => trim(optional($e->schoolClass)->name.' '.optional($e->section)->name));

        // Attendance rate per student for the year (whole-day register).
        $year = $yearId ? AcademicYear::find($yearId) : null;
        $att = StudentAttendance::whereNull('subject_id')->whereIn('student_id', $enrol->keys())
            ->when($year, fn ($q) => $q->whereDate('attendance_date', '>=', $year->start_date)->whereDate('attendance_date', '<=', $year->end_date))
            ->select('student_id', 'status', DB::raw('count(*) as c'))->groupBy('student_id', 'status')->get()->groupBy('student_id')
            ->map(function ($g) {
                $total = $g->sum('c');
                $present = $g->whereIn('status', StudentAttendance::presentStatuses())->sum('c');

                return ['rate' => $total ? round($present / $total * 100, 1) : null, 'absent' => (int) $g->where('status', 'absent')->sum('c')];
            })->filter(fn ($a) => $a['rate'] !== null);
        $bestAtt = $att->sortByDesc('rate')->keys()->first();
        $mostAbs = $att->sortByDesc('absent')->keys()->first();

        return response()->json([
            'pass_mark' => $perf['pass'],
            'max' => $perf['max'],
            'school_average' => $avgs->isNotEmpty() ? round($avgs->avg(), 2) : null,
            'graded' => $avgs->count(),
            'top' => $top,
            'best_class' => $classes->first(),
            'best_section' => $sections->first(),
            'at_risk' => $perf['pass'] !== null ? $avgs->filter(fn ($a) => $a < $perf['pass'])->count() : 0,
            'best_attendance' => $bestAtt ? ['id' => $bestAtt, 'name' => $names[$bestAtt] ?? '', 'place' => $place($bestAtt), 'rate' => $att[$bestAtt]['rate']] : null,
            'most_absences' => $mostAbs ? ['id' => $mostAbs, 'name' => $names[$mostAbs] ?? '', 'place' => $place($mostAbs), 'absent' => $att[$mostAbs]['absent']] : null,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Student::class);

        $companyId = $this->resolveActingCompanyId($request);
        $request->validate($this->rules());

        $data = $this->payload($request);
        $data['company_id'] = $companyId;
        $data['admission_number'] = $request->filled('admission_number')
            ? $request->admission_number
            : Student::generateAdmissionNumber();
        $data['image'] = $this->storeImage($request, $companyId);

        $student = Student::create($data);

        // Admitting straight into a class is the common case; skipping it is
        // allowed so a mid-year applicant can be registered before placement.
        if ($request->filled('class_id')) {
            $this->writeEnrollment($student, $request, $companyId);
        }

        return response()->json([
            'success' => true,
            'id' => $student->id,
            'admission_number' => $student->admission_number,
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Student::class);

        $student = Student::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $student->company_id === $this->resolveActingCompanyId($request), 404);
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;
        $enrollment = $student->enrollmentFor($yearId);

        $data = $this->present($student, $enrollment);
        $data += [
            'address' => $student->address,
            'city' => $student->city,
            'national_id' => $student->national_id,
            'medical_notes' => $student->medical_notes,
            'guardian_relation' => $student->guardian_relation,
            'guardian_email' => $student->guardian_email,
            'guardian_occupation' => $student->guardian_occupation,
            'admission_date' => optional($student->admission_date)->toDateString(),
            'notes' => $student->notes,
        ];

        $invoiceTotals = SchoolInvoice::whereNull('deleted_at')
            ->where('student_id', $student->id)->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(total),0) as billed, COALESCE(SUM(paid),0) as paid')
            ->first();

        $data['stats'] = [
            'attendance_rate' => $this->attendanceRate($student->id, $yearId),
            'present_days' => $this->attendanceCount($student->id, $yearId, StudentAttendance::presentStatuses()),
            'absent_days' => $this->attendanceCount($student->id, $yearId, ['absent']),
            'billed' => round((float) ($invoiceTotals->billed ?? 0), 2),
            'paid' => round((float) ($invoiceTotals->paid ?? 0), 2),
            'due' => round((float) ($invoiceTotals->billed ?? 0) - (float) ($invoiceTotals->paid ?? 0), 2),
            'enrollments' => StudentEnrollment::whereNull('deleted_at')->where('student_id', $student->id)->count(),
        ];

        return response()->json(['student' => $data]);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Student::class);

        $student = Student::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $student->company_id === $this->resolveActingCompanyId($request), 404);
        $enrollment = $student->enrollmentFor();

        return response()->json(['student' => array_merge($student->toArray(), [
            'image_url' => $student->image ? asset($student->image) : null,
            'date_of_birth' => optional($student->date_of_birth)->toDateString(),
            'admission_date' => optional($student->admission_date)->toDateString(),
            'class_id' => $enrollment ? $enrollment->class_id : null,
            'section_id' => $enrollment ? $enrollment->section_id : null,
            'roll_number' => $enrollment ? $enrollment->roll_number : null,
        ])]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Student::class);

        $companyId = $this->resolveActingCompanyId($request);
        $student = Student::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $student->company_id === $companyId, 404);

        $request->validate($this->rules($student->id));

        $data = $this->payload($request);
        if ($request->filled('admission_number')) {
            $data['admission_number'] = $request->admission_number;
        }

        $image = $this->storeImage($request, $companyId);
        if ($image) {
            $this->deleteImage($student->image);
            $data['image'] = $image;
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($student->image);
            $data['image'] = null;
        }

        $student->update($data);

        if ($request->filled('class_id')) {
            $this->writeEnrollment($student, $request, $companyId);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Student::class);

        $student = Student::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $student->company_id === $this->resolveActingCompanyId($request), 404);
        $student->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Student::class);

        $ids = (array) $request->selectedIds;
        Student::whereIn('id', $ids)->where('company_id', $this->resolveActingCompanyId($request))->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /** Everything filed under one student, for the detail page's tabs. */
    public function timeline(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Student::class);

        $student = Student::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $student->company_id === $this->resolveActingCompanyId($request), 404);
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $results = ExamResult::with('examSubject.subject', 'examSubject.exam')
            ->where('student_id', $student->id)
            ->orderBy('id', 'desc')->limit(100)->get()
            ->map(function ($r) {
                $es = $r->examSubject;
                $max = $es ? (float) $es->max_marks : 0;
                $marks = $r->marks === null ? null : (float) $r->marks;

                return [
                    'id' => $r->id,
                    'exam_name' => $es && $es->exam ? $es->exam->name : '',
                    'subject_name' => $es && $es->subject ? $es->subject->name : '',
                    'exam_date' => $es ? optional($es->exam_date)->toDateString() : null,
                    'marks' => $marks,
                    'max_marks' => $max,
                    'percentage' => ($marks !== null && $max > 0) ? round(($marks / $max) * 100, 1) : null,
                    'grade' => $r->grade,
                    'is_absent' => (bool) $r->is_absent,
                    'passed' => ($marks !== null && $es) ? $marks >= (float) $es->pass_marks : null,
                ];
            })->values();

        return response()->json([
            'enrollments' => StudentEnrollment::with('academicYear', 'schoolClass', 'section')
                ->whereNull('deleted_at')->where('student_id', $student->id)
                ->orderBy('id', 'desc')->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'year_name' => $e->academicYear ? $e->academicYear->name : '',
                    'class_name' => $e->schoolClass ? $e->schoolClass->name : '',
                    'section_name' => $e->section ? $e->section->name : null,
                    'roll_number' => $e->roll_number,
                    'status' => $e->status,
                    'enrolled_on' => optional($e->enrolled_on)->toDateString(),
                ])->values(),
            'attendance' => StudentAttendance::where('student_id', $student->id)
                ->orderBy('attendance_date', 'desc')->limit(60)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'attendance_date' => optional($a->attendance_date)->toDateString(),
                    'status' => $a->status,
                    'remarks' => $a->remarks,
                ])->values(),
            'attendance_summary' => $this->attendanceSummary($student->id, $yearId),
            'results' => $results,
            'invoices' => SchoolInvoice::whereNull('deleted_at')->where('student_id', $student->id)
                ->orderBy('invoice_date', 'desc')->limit(50)->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'reference' => $i->reference,
                    'invoice_date' => optional($i->invoice_date)->toDateString(),
                    'total' => (float) $i->total,
                    'paid' => (float) $i->paid,
                    'due' => $i->due,
                    'status' => $i->status,
                ])->values(),
        ]);
    }

    /**
     * Report card ("Libreta", 2026-10-21): the student's marks for one academic
     * year, one column per term exam (I-IV bimestre…), with each subject's
     * average, the term averages, the credit-weighted average, the absences per
     * term and the final situation -- plus the school's name / address for the
     * printed copy (modelled on MINEDU's "Reporte de notas de estudiante").
     */
    public function reportCard(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Student::class);

        $companyId = $this->resolveActingCompanyId($request);
        $student = Student::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $student->company_id === (int) $companyId, 404);
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;
        $enrollmentQ = fn () => StudentEnrollment::with('schoolClass', 'section.teacher')
            ->whereNull('deleted_at')->where('student_id', $student->id)->orderByDesc('id');
        $enrollment = $enrollmentQ()->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))->first();
        // Not enrolled in that year: the student's latest enrolment (and its year).
        if (! $enrollment && ! $request->academic_year_id) {
            $enrollment = $enrollmentQ()->first();
            $yearId = $enrollment ? $enrollment->academic_year_id : $yearId;
        }
        $year = $yearId ? AcademicYear::find($yearId) : null;

        $settings = \App\Models\Setting::forCompany((int) $companyId);
        $school = [
            'name' => $settings->CompanyName ?: DB::table('companies')->where('id', $companyId)->value('name'),
            'address' => $settings->CompanyAdress,
            'phone' => $settings->CompanyPhone,
            'email' => $settings->email,
            'logo_url' => $settings->logo ? asset($settings->logo) : null,
        ];

        $base = [
            'school' => $school,
            'student' => [
                'name' => $student->name, 'admission_number' => $student->admission_number,
                'national_id' => $student->national_id, 'date_of_birth' => optional($student->date_of_birth)->toDateString(),
                'guardian_name' => $student->guardian_name,
            ],
            'year' => $year ? ['id' => $year->id, 'name' => $year->name, 'start_date' => optional($year->start_date)->toDateString(), 'end_date' => optional($year->end_date)->toDateString()] : null,
            'enrollment' => $enrollment ? [
                'class_name' => optional($enrollment->schoolClass)->name,
                'section_name' => optional($enrollment->section)->name,
                'roll_number' => $enrollment->roll_number,
                'tutor' => optional(optional($enrollment->section)->teacher)->name,
            ] : null,
            'issued_at' => now()->toDateTimeString(),
        ];
        if (! $enrollment || ! $year) {
            return response()->json($base + ['terms' => [], 'subjects' => [], 'summary' => null]);
        }

        // The class's exams of the year, in date order: one column each.
        $exams = \App\Models\Exam::whereNull('deleted_at')->where('academic_year_id', $yearId)
            ->whereHas('examSubjects', fn ($q) => $q->where('class_id', $enrollment->class_id))
            ->orderBy('start_date')->get(['id', 'name', 'term', 'start_date', 'end_date', 'status']);
        $papers = \App\Models\ExamSubject::with('subject')->whereIn('exam_id', $exams->pluck('id'))
            ->where('class_id', $enrollment->class_id)->get();
        $results = ExamResult::whereIn('exam_subject_id', $papers->pluck('id'))->where('student_id', $student->id)
            ->get()->keyBy('exam_subject_id');

        $max = (float) ($papers->max('max_marks') ?: 20);
        $pass = (float) ($papers->max('pass_marks') ?: round($max * 0.55));
        $vigesimal = $max === 20.0;
        $round = fn ($v) => $v === null ? null : ($vigesimal ? (int) floor($v + 0.5) : round($v, 1));

        $subjects = [];
        foreach ($papers->groupBy('subject_id') as $subjectId => $rows) {
            $subject = $rows->first()->subject;
            $marks = [];
            $nums = [];
            foreach ($exams as $exam) {
                $paper = $rows->firstWhere('exam_id', $exam->id);
                $r = $paper ? $results->get($paper->id) : null;
                if ($r && $r->marks !== null) {
                    $marks[$exam->id] = (float) $r->marks;
                    $nums[] = (float) $r->marks;
                } else {
                    $marks[$exam->id] = $r && $r->is_absent ? 'AUS' : null;
                }
            }
            $avg = $nums ? $round(array_sum($nums) / count($nums)) : null;
            $subjects[] = [
                'subject_id' => (int) $subjectId,
                'name' => $subject ? $subject->name : '',
                'credit' => (float) ($subject->credit ?? 1) ?: 1.0,
                'marks' => $marks,
                'average' => $avg,
                'grade' => $avg === null ? null : ExamResult::gradeFor($avg / $max * 100, $max),
                'passed' => $avg === null ? null : $avg >= $pass,
            ];
        }
        usort($subjects, fn ($a, $b) => strcmp($a['name'], $b['name']));

        // Term averages (each column) and the credit-weighted average of the subject averages.
        $termAvg = [];
        foreach ($exams as $exam) {
            $vals = array_filter(array_map(fn ($s) => is_numeric($s['marks'][$exam->id]) ? $s['marks'][$exam->id] : null, $subjects), fn ($v) => $v !== null);
            $termAvg[$exam->id] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
        }
        $graded = array_filter($subjects, fn ($s) => $s['average'] !== null);
        $credits = array_sum(array_map(fn ($s) => $s['credit'], $graded));
        $weighted = $credits > 0 ? round(array_sum(array_map(fn ($s) => $s['average'] * $s['credit'], $graded)) / $credits, 2) : null;

        // Absences per term: from the year's start (or the previous exam) to each exam's last day.
        $attendance = [];
        $from = $year->start_date;
        foreach ($exams as $exam) {
            $to = $exam->end_date ?: $exam->start_date;
            $counts = StudentAttendance::whereNull('subject_id')->where('student_id', $student->id)
                ->whereDate('attendance_date', '>=', $from)->whereDate('attendance_date', '<=', $to)
                ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
            $attendance[$exam->id] = ['absent' => (int) ($counts['absent'] ?? 0), 'late' => (int) ($counts['late'] ?? 0),
                'excused' => (int) ($counts['excused'] ?? 0), 'days' => (int) $counts->sum()];
            $from = optional($to)->copy()->addDay();
        }

        $pending = $exams->contains(fn ($e) => ! in_array($e->status, ['published', 'completed'], true));
        $failed = count(array_filter($graded, fn ($s) => ! $s['passed']));

        return response()->json($base + [
            'scale' => ['max' => $max, 'pass' => $pass],
            'terms' => $exams->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'term' => $e->term,
                'status' => $e->status, 'average' => $termAvg[$e->id], 'attendance' => $attendance[$e->id]])->values(),
            'subjects' => $subjects,
            'summary' => [
                'weighted_average' => $weighted,
                'weighted_grade' => $weighted === null ? null : ExamResult::gradeFor($weighted / $max * 100, $max),
                'credits' => $credits,
                'failed_subjects' => $failed,
                // Year not over: in progress; then passed, or failed in N subjects.
                'situation' => $pending ? 'in_progress' : ($failed ? 'failed' : 'passed'),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Enrolment
    // ------------------------------------------------------------------

    public function enrollments(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StudentEnrollment::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));

        $query = StudentEnrollment::with('student', 'academicYear', 'schoolClass', 'section')
            ->whereNull('deleted_at')
            ->where('company_id', $this->resolveActingCompanyId($request))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->academic_year_id))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereHas('student', fn ($s) => $s->where('name', 'LIKE', "%{$request->search}%")
                    ->orWhere('admission_number', 'LIKE', "%{$request->search}%"));
            });

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy('class_id')->orderBy('section_id')->orderBy('roll_number')
            ->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'enrollments' => $rows->map(fn ($e) => [
                'id' => $e->id,
                'student_id' => $e->student_id,
                'student_name' => $e->student ? $e->student->name : '',
                'admission_number' => $e->student ? $e->student->admission_number : '',
                'academic_year_id' => $e->academic_year_id,
                'year_name' => $e->academicYear ? $e->academicYear->name : '',
                'class_id' => $e->class_id,
                'class_name' => $e->schoolClass ? $e->schoolClass->name : '',
                'section_id' => $e->section_id,
                'section_name' => $e->section ? $e->section->name : null,
                'roll_number' => $e->roll_number,
                'status' => $e->status,
                'enrolled_on' => optional($e->enrolled_on)->toDateString(),
            ])->values(),
        ]);
    }

    public function storeEnrollment(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', StudentEnrollment::class);

        $companyId = $this->resolveActingCompanyId($request);

        $request->validate([
            'student_id' => 'required|exists:students,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:class_sections,id',
        ]);

        $student = Student::findOrFail($request->student_id);
        abort_unless((int) $student->company_id === $companyId, 404);
        $this->writeEnrollment($student, $request, $companyId);

        return response()->json(['success' => true]);
    }

    public function updateEnrollment(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', StudentEnrollment::class);

        $request->validate([
            'class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:class_sections,id',
            'status' => 'required|in:active,promoted,transferred,left,repeated',
        ]);

        $enrollment = StudentEnrollment::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $enrollment->company_id === $this->resolveActingCompanyId($request), 404);

        $enrollment->update([
            'class_id' => $request->class_id,
            'section_id' => $request->section_id ?: null,
            'roll_number' => $request->roll_number,
            'status' => $request->status,
            'notes' => $request->notes,
        ]);

        return response()->json(['success' => true]);
    }

    public function destroyEnrollment(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', StudentEnrollment::class);

        $enrollment = StudentEnrollment::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $enrollment->company_id === $this->resolveActingCompanyId($request), 404);
        $enrollment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Promote a class into the next academic year.
     *
     * The old enrolment is marked `promoted` (or `repeated`) and a NEW one is
     * created for the next year — history is never rewritten. Students who
     * already have an enrolment in the target year are skipped rather than
     * duplicated, so running this twice is safe.
     */
    public function promote(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', StudentEnrollment::class);

        $companyId = $this->resolveActingCompanyId($request);

        $request->validate([
            'from_year_id' => 'required|exists:academic_years,id',
            'to_year_id' => 'required|exists:academic_years,id|different:from_year_id',
            'class_id' => 'required|exists:school_classes,id',
            'to_class_id' => 'required|exists:school_classes,id',
            'to_section_id' => 'nullable|exists:class_sections,id',
            'student_ids' => 'nullable|array',
            'repeat_ids' => 'nullable|array',
        ]);

        $repeatIds = array_map('intval', (array) $request->repeat_ids);

        $source = StudentEnrollment::whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('academic_year_id', $request->from_year_id)
            ->where('class_id', $request->class_id)
            ->where('status', 'active')
            ->when($request->filled('student_ids'), fn ($q) => $q->whereIn('student_id', (array) $request->student_ids))
            ->get();

        $promoted = 0;
        $repeated = 0;
        $skipped = 0;

        DB::transaction(function () use ($source, $request, $repeatIds, $companyId, &$promoted, &$repeated, &$skipped) {
            foreach ($source as $enrollment) {
                $already = StudentEnrollment::whereNull('deleted_at')
                    ->where('student_id', $enrollment->student_id)
                    ->where('academic_year_id', $request->to_year_id)
                    ->exists();
                if ($already) {
                    $skipped++;
                    continue;
                }

                $repeats = in_array((int) $enrollment->student_id, $repeatIds, true);

                StudentEnrollment::create([
                    'company_id' => $companyId,
                    'student_id' => $enrollment->student_id,
                    'academic_year_id' => $request->to_year_id,
                    // A repeater stays in the class they were in.
                    'class_id' => $repeats ? $enrollment->class_id : $request->to_class_id,
                    'section_id' => $repeats ? $enrollment->section_id : ($request->to_section_id ?: null),
                    'roll_number' => $enrollment->roll_number,
                    'enrolled_on' => now()->toDateString(),
                    'status' => 'active',
                ]);

                $enrollment->update(['status' => $repeats ? 'repeated' : 'promoted']);
                $repeats ? $repeated++ : $promoted++;
            }
        });

        return response()->json([
            'success' => true,
            'promoted' => $promoted,
            'repeated' => $repeated,
            'skipped' => $skipped,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create or move the student's enrolment for the given year. One enrolment
     * per student per year is a DB constraint, so this updates in place rather
     * than inserting a second.
     */
    private function writeEnrollment(Student $student, Request $request, int $companyId)
    {
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;
        if (! $yearId) {
            return null;
        }

        $existing = StudentEnrollment::whereNull('deleted_at')
            ->where('student_id', $student->id)
            ->where('academic_year_id', $yearId)
            ->first();

        $payload = [
            'class_id' => $request->class_id,
            'section_id' => $request->section_id ?: null,
            'roll_number' => $request->roll_number,
            'enrolled_on' => $request->enrolled_on ?: ($student->admission_date ?: now()->toDateString()),
            'status' => $request->enrollment_status ?: 'active',
        ];

        if ($existing) {
            $existing->update($payload);

            return $existing;
        }

        return StudentEnrollment::create($payload + [
            'company_id' => $companyId,
            'student_id' => $student->id,
            'academic_year_id' => $yearId,
        ]);
    }

    private function attendanceCount($studentId, $yearId, array $statuses)
    {
        return StudentAttendance::where('student_id', $studentId)
            ->whereNull('subject_id')
            ->whereIn('status', $statuses)
            ->when($yearId, function ($q) use ($yearId) {
                $year = AcademicYear::find($yearId);
                if ($year) {
                    $q->whereDate('attendance_date', '>=', $year->start_date)
                        ->whereDate('attendance_date', '<=', $year->end_date);
                }
            })
            ->count();
    }

    /** Percentage of marked days the student was in school; null if never marked. */
    private function attendanceRate($studentId, $yearId)
    {
        $present = $this->attendanceCount($studentId, $yearId, StudentAttendance::presentStatuses());
        $total = $this->attendanceCount($studentId, $yearId, ['present', 'late', 'half_day', 'absent', 'excused']);

        return $total > 0 ? round(($present / $total) * 100, 1) : null;
    }

    private function attendanceSummary($studentId, $yearId)
    {
        $rows = StudentAttendance::where('student_id', $studentId)
            ->whereNull('subject_id')
            ->when($yearId, function ($q) use ($yearId) {
                $year = AcademicYear::find($yearId);
                if ($year) {
                    $q->whereDate('attendance_date', '>=', $year->start_date)
                        ->whereDate('attendance_date', '<=', $year->end_date);
                }
            })
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')->pluck('aggregate', 'status')->toArray();

        return [
            'present' => (int) ($rows['present'] ?? 0),
            'absent' => (int) ($rows['absent'] ?? 0),
            'late' => (int) ($rows['late'] ?? 0),
            'excused' => (int) ($rows['excused'] ?? 0),
            'half_day' => (int) ($rows['half_day'] ?? 0),
            'rate' => $this->attendanceRate($studentId, $yearId),
        ];
    }

    private function present(Student $student, $enrollment = null)
    {
        return [
            'id' => $student->id,
            'admission_number' => $student->admission_number,
            'name' => $student->name,
            'gender' => $student->gender,
            'date_of_birth' => optional($student->date_of_birth)->toDateString(),
            'age' => $student->age,
            'blood_group' => $student->blood_group,
            'phone' => $student->phone,
            'email' => $student->email,
            'guardian_name' => $student->guardian_name,
            'guardian_phone' => $student->guardian_phone,
            'status' => $student->status,
            'client_id' => $student->client_id,
            'image' => $student->image,
            'image_url' => $student->image ? asset($student->image) : null,
            'enrollment_id' => $enrollment ? $enrollment->id : null,
            'class_id' => $enrollment ? $enrollment->class_id : null,
            'class_name' => $enrollment && $enrollment->schoolClass ? $enrollment->schoolClass->name : null,
            'section_id' => $enrollment ? $enrollment->section_id : null,
            'section_name' => $enrollment && $enrollment->section ? $enrollment->section->name : null,
            'roll_number' => $enrollment ? $enrollment->roll_number : null,
            'created_at' => optional($student->created_at)->toIso8601String(),
        ];
    }

    private function rules($ignoreId = null)
    {
        return [
            'name' => 'required|string|max:191',
            'gender' => 'required|in:male,female,other',
            'status' => 'required|in:active,inactive,graduated,transferred,expelled',
            'admission_number' => 'nullable|string|max:32|unique:students,admission_number' . ($ignoreId ? ',' . $ignoreId : ''),
            'date_of_birth' => 'nullable|date|before_or_equal:today',
            'admission_date' => 'nullable|date',
            'email' => 'nullable|email|max:191',
            'guardian_email' => 'nullable|email|max:191',
            'client_id' => 'nullable|exists:clients,id',
            'class_id' => 'nullable|exists:school_classes,id',
            'section_id' => 'nullable|exists:class_sections,id',
            'image' => 'nullable|image|max:5120',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'name' => $request->name,
            'gender' => $request->gender,
            'date_of_birth' => $request->date_of_birth ?: null,
            'admission_date' => $request->admission_date ?: null,
            'blood_group' => $request->blood_group ?: null,
            'phone' => $request->phone,
            'email' => $request->email,
            'address' => $request->address,
            'city' => $request->city,
            'national_id' => $request->national_id,
            'medical_notes' => $request->medical_notes,
            'guardian_name' => $request->guardian_name,
            'guardian_relation' => $request->guardian_relation,
            'guardian_phone' => $request->guardian_phone,
            'guardian_email' => $request->guardian_email,
            'guardian_occupation' => $request->guardian_occupation,
            'client_id' => $request->client_id ?: null,
            'notes' => $request->notes,
            'status' => $request->status,
        ];
    }

    /** Returns the stored image's public-relative path, or null when no photo was sent. */
    private function storeImage(Request $request, int $companyId)
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $file = $request->file('image');
        $rel = CompanyFiles::dir($companyId, 'students');
        $path = public_path($rel);

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
        $filename = time() . '_' . Str::random(10) . '.' . $extension;
        $file->move($path, $filename);

        return $rel . '/' . $filename;
    }

    /** $relPath is the public-relative path stored in the `image` column. */
    private function deleteImage(?string $relPath)
    {
        if (! $relPath) {
            return;
        }
        $path = public_path($relPath);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
