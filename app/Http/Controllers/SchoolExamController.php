<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Exams, their schedules and results.
 *
 * The grade is DERIVED on save from ExamResult::gradeFor() rather than typed
 * per row — one scale, applied identically to every mark, so nobody has to
 * agree about a 79.5 twice. Re-entering a mark re-derives it.
 *
 * `marks = null` means absent; `marks = 0` means they sat it and scored
 * nothing. Averages ignore the first and include the second, which is the only
 * way a class average survives a flu week honestly.
 */
class SchoolExamController extends Controller
{
    private const SORTABLE = ['id', 'name', 'term', 'start_date', 'status', 'created_at'];

    // ------------------------------------------------------------------
    // Exams
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Exam::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'start_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Exam::with('academicYear')->whereNull('deleted_at')
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->academic_year_id))
            ->when($request->filled('term'), fn ($q) => $q->where('term', $request->term))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"));

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        $paperCounts = ExamSubject::whereIn('exam_id', $rows->pluck('id'))
            ->select('exam_id', DB::raw('count(*) as aggregate'))
            ->groupBy('exam_id')->pluck('aggregate', 'exam_id')->toArray();

        return response()->json([
            'totalRows' => $totalRows,
            'exams' => $rows->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'term' => $e->term,
                'academic_year_id' => $e->academic_year_id,
                'year_name' => $e->academicYear ? $e->academicYear->name : '',
                'start_date' => optional($e->start_date)->toDateString(),
                'end_date' => optional($e->end_date)->toDateString(),
                'status' => $e->status,
                'notes' => $e->notes,
                'papers' => (int) ($paperCounts[$e->id] ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Exam::class);

        $request->validate($this->rules());
        Exam::create($this->payload($request));

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Exam::class);

        $request->validate($this->rules());
        Exam::whereNull('deleted_at')->findOrFail($id)->update($this->payload($request));

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Exam::class);

        $exam = Exam::whereNull('deleted_at')->findOrFail($id);

        $entered = ExamResult::whereIn('exam_subject_id', ExamSubject::where('exam_id', $exam->id)->pluck('id'))
            ->whereNotNull('marks')->count();
        if ($entered) {
            return response()->json([
                'message' => "This exam already has {$entered} mark(s) recorded. Delete those first if you really mean to remove it.",
            ], 422);
        }

        DB::transaction(function () use ($exam) {
            $paperIds = ExamSubject::where('exam_id', $exam->id)->pluck('id');
            ExamResult::whereIn('exam_subject_id', $paperIds)->delete();
            ExamSubject::where('exam_id', $exam->id)->delete();
            $exam->delete();
        });

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Schedule (papers)
    // ------------------------------------------------------------------

    public function papers(Request $request, $examId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Exam::class);

        $exam = Exam::with('academicYear')->whereNull('deleted_at')->findOrFail($examId);

        $papers = ExamSubject::with('subject', 'schoolClass')
            ->where('exam_id', $exam->id)
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->orderBy('exam_date')->orderBy('start_time')->get();

        $entered = ExamResult::whereIn('exam_subject_id', $papers->pluck('id'))
            ->whereNotNull('marks')
            ->select('exam_subject_id', DB::raw('count(*) as aggregate'))
            ->groupBy('exam_subject_id')->pluck('aggregate', 'exam_subject_id')->toArray();

        return response()->json([
            'exam' => [
                'id' => $exam->id,
                'name' => $exam->name,
                'term' => $exam->term,
                'status' => $exam->status,
                'academic_year_id' => $exam->academic_year_id,
                'year_name' => $exam->academicYear ? $exam->academicYear->name : '',
            ],
            'papers' => $papers->map(fn ($p) => [
                'id' => $p->id,
                'class_id' => $p->class_id,
                'class_name' => $p->schoolClass ? $p->schoolClass->name : '',
                'subject_id' => $p->subject_id,
                'subject_name' => $p->subject ? $p->subject->name : '',
                'exam_date' => optional($p->exam_date)->toDateString(),
                'start_time' => $p->start_time ? substr((string) $p->start_time, 0, 5) : null,
                'duration_minutes' => $p->duration_minutes,
                'max_marks' => (float) $p->max_marks,
                'pass_marks' => (float) $p->pass_marks,
                'room' => $p->room,
                'results_entered' => (int) ($entered[$p->id] ?? 0),
            ])->values(),
        ]);
    }

    public function storePaper(Request $request, $examId)
    {
        $this->authorizeForUser($request->user('api'), 'create', Exam::class);

        $request->validate($this->paperRules());
        $exam = Exam::whereNull('deleted_at')->findOrFail($examId);

        $exists = ExamSubject::where('exam_id', $exam->id)
            ->where('class_id', $request->class_id)
            ->where('subject_id', $request->subject_id)->exists();
        if ($exists) {
            return response()->json(['message' => 'That subject is already scheduled for this class.'], 422);
        }

        ExamSubject::create($this->paperPayload($request) + ['exam_id' => $exam->id]);

        return response()->json(['success' => true]);
    }

    public function updatePaper(Request $request, $examId, $paperId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Exam::class);

        $request->validate($this->paperRules());
        $paper = ExamSubject::where('exam_id', $examId)->findOrFail($paperId);
        $paper->update($this->paperPayload($request));

        // Max marks may have moved; the grades must follow it.
        $this->regradePaper($paper->fresh());

        return response()->json(['success' => true]);
    }

    public function destroyPaper(Request $request, $examId, $paperId)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Exam::class);

        $paper = ExamSubject::where('exam_id', $examId)->findOrFail($paperId);

        DB::transaction(function () use ($paper) {
            ExamResult::where('exam_subject_id', $paper->id)->delete();
            $paper->delete();
        });

        return response()->json(['success' => true]);
    }

    /** Schedule every active subject of a class in one go. */
    public function generatePapers(Request $request, $examId)
    {
        $this->authorizeForUser($request->user('api'), 'create', Exam::class);

        $request->validate([
            'class_id' => 'required|exists:school_classes,id',
            'max_marks' => 'nullable|numeric|min:1',
            'pass_marks' => 'nullable|numeric|min:0',
        ]);

        $exam = Exam::whereNull('deleted_at')->findOrFail($examId);
        $subjects = Subject::whereNull('deleted_at')
            ->where('class_id', $request->class_id)->where('is_active', 1)->get();

        $created = 0;
        foreach ($subjects as $subject) {
            $exists = ExamSubject::where('exam_id', $exam->id)
                ->where('class_id', $request->class_id)
                ->where('subject_id', $subject->id)->exists();
            if ($exists) {
                continue;
            }

            ExamSubject::create([
                'exam_id' => $exam->id,
                'class_id' => $request->class_id,
                'subject_id' => $subject->id,
                'max_marks' => $request->max_marks ?: 100,
                // The subject's own pass mark is a percentage of the paper.
                'pass_marks' => $request->pass_marks ?: round((($request->max_marks ?: 100) * $subject->pass_mark) / 100, 2),
            ]);
            $created++;
        }

        if (! $created) {
            return response()->json(['message' => 'Every subject for that class is already scheduled.'], 422);
        }

        return response()->json(['success' => true, 'count' => $created]);
    }

    // ------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------

    /** The mark sheet for one paper: every enrolled student, with any mark. */
    public function sheet(Request $request, $examId, $paperId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Exam::class);

        $exam = Exam::whereNull('deleted_at')->findOrFail($examId);
        $paper = ExamSubject::with('subject', 'schoolClass')->where('exam_id', $exam->id)->findOrFail($paperId);

        $enrollments = StudentEnrollment::with('student', 'section')
            ->whereNull('deleted_at')
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('class_id', $paper->class_id)
            ->where('status', 'active')
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->get()
            ->filter(fn ($e) => $e->student !== null)
            ->sortBy(fn ($e) => [$e->roll_number ?: 'zzz', $e->student->name]);

        $results = ExamResult::where('exam_subject_id', $paper->id)
            ->whereIn('student_id', $enrollments->pluck('student_id'))
            ->get()->keyBy('student_id');

        return response()->json([
            'paper' => [
                'id' => $paper->id,
                'exam_name' => $exam->name,
                'subject_name' => $paper->subject ? $paper->subject->name : '',
                'class_name' => $paper->schoolClass ? $paper->schoolClass->name : '',
                'max_marks' => (float) $paper->max_marks,
                'pass_marks' => (float) $paper->pass_marks,
                'exam_date' => optional($paper->exam_date)->toDateString(),
            ],
            'students' => $enrollments->values()->map(function ($e) use ($results) {
                $result = $results->get($e->student_id);

                return [
                    'student_id' => $e->student_id,
                    'name' => $e->student->name,
                    'admission_number' => $e->student->admission_number,
                    'roll_number' => $e->roll_number,
                    'section_name' => $e->section ? $e->section->name : null,
                    'marks' => $result && $result->marks !== null ? (float) $result->marks : null,
                    'is_absent' => $result ? (bool) $result->is_absent : false,
                    'grade' => $result ? $result->grade : null,
                    'remarks' => $result ? $result->remarks : null,
                ];
            })->values(),
        ]);
    }

    /** Save a whole mark sheet; grades are derived, never accepted. */
    public function saveResults(Request $request, $examId, $paperId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Exam::class);

        $paper = ExamSubject::where('exam_id', $examId)->findOrFail($paperId);
        $max = (float) $paper->max_marks;

        $request->validate([
            'results' => 'required|array|min:1',
            'results.*.student_id' => 'required|integer',
            'results.*.marks' => 'nullable|numeric|min:0|max:' . $max,
        ]);

        $userId = optional($request->user('api'))->id;
        $saved = 0;

        DB::transaction(function () use ($request, $paper, $max, $userId, &$saved) {
            foreach ($request->results as $row) {
                $absent = ! empty($row['is_absent']);
                $marks = $absent ? null : (isset($row['marks']) && $row['marks'] !== '' ? (float) $row['marks'] : null);
                $percentage = ($marks !== null && $max > 0) ? ($marks / $max) * 100 : null;

                ExamResult::updateOrCreate(
                    ['exam_subject_id' => $paper->id, 'student_id' => $row['student_id']],
                    [
                        'marks' => $marks,
                        'is_absent' => $absent,
                        'grade' => $absent ? 'AB' : ExamResult::gradeFor($percentage),
                        'remarks' => $row['remarks'] ?? null,
                        'entered_by' => $userId,
                    ]
                );
                $saved++;
            }
        });

        return response()->json(['success' => true, 'saved' => $saved]);
    }

    /**
     * The full report card for one class in one exam: every student, every
     * subject, totals, percentage, grade and position.
     */
    public function reportCard(Request $request, $examId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Exam::class);

        $request->validate(['class_id' => 'required|exists:school_classes,id']);

        $exam = Exam::whereNull('deleted_at')->findOrFail($examId);
        $papers = ExamSubject::with('subject')->where('exam_id', $exam->id)
            ->where('class_id', $request->class_id)->get();

        $enrollments = StudentEnrollment::with('student', 'section')
            ->whereNull('deleted_at')
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('class_id', $request->class_id)
            ->where('status', 'active')
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->get()->filter(fn ($e) => $e->student !== null);

        $results = ExamResult::whereIn('exam_subject_id', $papers->pluck('id'))->get()
            ->groupBy('student_id');

        $totalMax = (float) $papers->sum('max_marks');

        $rows = $enrollments->map(function ($e) use ($papers, $results, $totalMax) {
            $mine = $results->get($e->student_id, collect())->keyBy('exam_subject_id');
            $obtained = 0;
            $subjects = [];
            $failed = 0;

            foreach ($papers as $paper) {
                $result = $mine->get($paper->id);
                $marks = $result && $result->marks !== null ? (float) $result->marks : null;
                if ($marks !== null) {
                    $obtained += $marks;
                    if ($marks < (float) $paper->pass_marks) {
                        $failed++;
                    }
                } elseif ($result && $result->is_absent) {
                    $failed++;
                }

                $subjects[] = [
                    'subject_name' => $paper->subject ? $paper->subject->name : '',
                    'max_marks' => (float) $paper->max_marks,
                    'marks' => $marks,
                    'grade' => $result ? $result->grade : null,
                    'is_absent' => $result ? (bool) $result->is_absent : false,
                ];
            }

            $percentage = $totalMax > 0 ? round(($obtained / $totalMax) * 100, 2) : null;

            return [
                'student_id' => $e->student_id,
                'name' => $e->student->name,
                'admission_number' => $e->student->admission_number,
                'roll_number' => $e->roll_number,
                'section_name' => $e->section ? $e->section->name : null,
                'subjects' => $subjects,
                'obtained' => round($obtained, 2),
                'total' => round($totalMax, 2),
                'percentage' => $percentage,
                'grade' => ExamResult::gradeFor($percentage),
                'failed_subjects' => $failed,
                'result' => $failed > 0 ? 'Fail' : 'Pass',
            ];
        })->values();

        // Position by percentage, ties sharing a place (1,2,2,4) — the standard
        // school convention, and the only one that is defensible to a parent.
        $sorted = $rows->sortByDesc('percentage')->values();
        $ranked = [];
        $lastPercentage = null;
        $lastPosition = 0;
        foreach ($sorted as $i => $row) {
            $position = ($lastPercentage !== null && $row['percentage'] === $lastPercentage)
                ? $lastPosition
                : $i + 1;
            $lastPercentage = $row['percentage'];
            $lastPosition = $position;
            $ranked[] = $row + ['position' => $position];
        }

        return response()->json([
            'exam' => ['id' => $exam->id, 'name' => $exam->name, 'term' => $exam->term],
            'papers' => $papers->map(fn ($p) => [
                'id' => $p->id,
                'subject_name' => $p->subject ? $p->subject->name : '',
                'max_marks' => (float) $p->max_marks,
            ])->values(),
            'rows' => $ranked,
            'class_average' => count($ranked)
                ? round(collect($ranked)->avg('percentage'), 2)
                : null,
            'pass_rate' => count($ranked)
                ? round((collect($ranked)->where('result', 'Pass')->count() / count($ranked)) * 100, 1)
                : null,
        ]);
    }

    /** Re-derive every grade on a paper, e.g. after its max marks changed. */
    private function regradePaper(ExamSubject $paper)
    {
        $max = (float) $paper->max_marks;
        if ($max <= 0) {
            return;
        }

        ExamResult::where('exam_subject_id', $paper->id)->whereNotNull('marks')->get()
            ->each(function ($result) use ($max) {
                $result->update(['grade' => ExamResult::gradeFor(((float) $result->marks / $max) * 100)]);
            });
    }

    private function rules()
    {
        return [
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string|max:191',
            'term' => 'required|in:term_1,term_2,term_3,final,other',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|in:draft,scheduled,ongoing,completed,published',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'academic_year_id' => $request->academic_year_id,
            'name' => $request->name,
            'term' => $request->term,
            'start_date' => $request->start_date ?: null,
            'end_date' => $request->end_date ?: null,
            'status' => $request->status,
            'notes' => $request->notes,
        ];
    }

    private function paperRules()
    {
        return [
            'class_id' => 'required|exists:school_classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'exam_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'duration_minutes' => 'nullable|integer|min:5|max:600',
            'max_marks' => 'required|numeric|min:1',
            'pass_marks' => 'required|numeric|min:0|lte:max_marks',
        ];
    }

    private function paperPayload(Request $request)
    {
        return [
            'class_id' => $request->class_id,
            'subject_id' => $request->subject_id,
            'exam_date' => $request->exam_date ?: null,
            'start_time' => $request->start_time ?: null,
            'duration_minutes' => $request->duration_minutes ?: null,
            'max_marks' => $request->max_marks,
            'pass_marks' => $request->pass_marks,
            'room' => $request->room,
        ];
    }
}
