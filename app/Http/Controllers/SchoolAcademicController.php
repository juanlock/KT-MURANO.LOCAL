<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\SchoolClass;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The academic structure: years, classes, sections and subjects.
 *
 * They live in one controller because they are one setup screen and share one
 * permission (`school_academics`) — a school configures them together, at the
 * start of a year, and rarely touches them again.
 *
 * Deletes refuse rather than cascade wherever a record still holds students:
 * losing a class must never silently orphan an enrolment, a result or a bill.
 */
class SchoolAcademicController extends Controller
{
    // ------------------------------------------------------------------
    // Academic years
    // ------------------------------------------------------------------

    public function years(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SchoolClass::class);

        $years = AcademicYear::whereNull('deleted_at')->orderBy('start_date', 'desc')->get();
        $counts = StudentEnrollment::whereNull('deleted_at')
            ->select('academic_year_id', DB::raw('count(*) as aggregate'))
            ->groupBy('academic_year_id')->pluck('aggregate', 'academic_year_id')->toArray();

        return response()->json([
            'totalRows' => $years->count(),
            'academic_years' => $years->map(fn ($y) => [
                'id' => $y->id,
                'name' => $y->name,
                'start_date' => optional($y->start_date)->toDateString(),
                'end_date' => optional($y->end_date)->toDateString(),
                'is_current' => (bool) $y->is_current,
                'is_locked' => (bool) $y->is_locked,
                'students' => (int) ($counts[$y->id] ?? 0),
            ])->values(),
        ]);
    }

    public function storeYear(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SchoolClass::class);

        $request->validate($this->yearRules());
        $year = AcademicYear::create($this->yearPayload($request));
        $this->enforceSingleCurrent($year);

        return response()->json(['success' => true]);
    }

    public function updateYear(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', SchoolClass::class);

        $request->validate($this->yearRules());
        $year = AcademicYear::whereNull('deleted_at')->findOrFail($id);
        $year->update($this->yearPayload($request));
        $this->enforceSingleCurrent($year);

        return response()->json(['success' => true]);
    }

    public function destroyYear(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SchoolClass::class);

        $year = AcademicYear::whereNull('deleted_at')->findOrFail($id);

        $enrolled = StudentEnrollment::whereNull('deleted_at')->where('academic_year_id', $year->id)->count();
        if ($enrolled) {
            return response()->json([
                'message' => "This year has {$enrolled} enrolment(s). Delete those first, or lock the year instead.",
            ], 422);
        }

        $year->delete();

        return response()->json(['success' => true]);
    }

    /** Exactly one year may be current; setting a new one clears the old. */
    private function enforceSingleCurrent(AcademicYear $year)
    {
        if ($year->is_current) {
            AcademicYear::where('id', '!=', $year->id)->update(['is_current' => 0]);
        }
    }

    // ------------------------------------------------------------------
    // Classes
    // ------------------------------------------------------------------

    public function classes(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SchoolClass::class);

        $classes = SchoolClass::whereNull('deleted_at')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->orderBy('level')->orderBy('name')->get();

        $sectionCounts = ClassSection::whereNull('deleted_at')
            ->select('class_id', DB::raw('count(*) as aggregate'))
            ->groupBy('class_id')->pluck('aggregate', 'class_id')->toArray();
        $subjectCounts = Subject::whereNull('deleted_at')
            ->select('class_id', DB::raw('count(*) as aggregate'))
            ->groupBy('class_id')->pluck('aggregate', 'class_id')->toArray();

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;
        $studentCounts = StudentEnrollment::whereNull('deleted_at')
            ->where('status', 'active')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->select('class_id', DB::raw('count(*) as aggregate'))
            ->groupBy('class_id')->pluck('aggregate', 'class_id')->toArray();

        return response()->json([
            'totalRows' => $classes->count(),
            'classes' => $classes->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'level' => (int) $c->level,
                'description' => $c->description,
                'is_active' => (bool) $c->is_active,
                'sections' => (int) ($sectionCounts[$c->id] ?? 0),
                'subjects' => (int) ($subjectCounts[$c->id] ?? 0),
                'students' => (int) ($studentCounts[$c->id] ?? 0),
            ])->values(),
        ]);
    }

    public function storeClass(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SchoolClass::class);

        $request->validate($this->classRules());
        SchoolClass::create($this->classPayload($request));

        return response()->json(['success' => true]);
    }

    public function updateClass(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', SchoolClass::class);

        $request->validate($this->classRules());
        SchoolClass::whereNull('deleted_at')->findOrFail($id)->update($this->classPayload($request));

        return response()->json(['success' => true]);
    }

    public function destroyClass(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SchoolClass::class);

        $class = SchoolClass::whereNull('deleted_at')->findOrFail($id);

        $enrolled = StudentEnrollment::whereNull('deleted_at')->where('class_id', $class->id)->count();
        if ($enrolled) {
            return response()->json([
                'message' => "This class has {$enrolled} enrolment(s). Move those students first.",
            ], 422);
        }

        ClassSection::where('class_id', $class->id)->delete();
        Subject::where('class_id', $class->id)->update(['class_id' => null]);
        $class->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    public function sections(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SchoolClass::class);

        $sections = ClassSection::with('schoolClass', 'teacher')->whereNull('deleted_at')
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->orderBy('class_id')->orderBy('name')->get();

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;
        $counts = StudentEnrollment::whereNull('deleted_at')
            ->where('status', 'active')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->select('section_id', DB::raw('count(*) as aggregate'))
            ->groupBy('section_id')->pluck('aggregate', 'section_id')->toArray();

        return response()->json([
            'totalRows' => $sections->count(),
            'sections' => $sections->map(function ($s) use ($counts) {
                $enrolled = (int) ($counts[$s->id] ?? 0);

                return [
                    'id' => $s->id,
                    'class_id' => $s->class_id,
                    'class_name' => $s->schoolClass ? $s->schoolClass->name : '',
                    'name' => $s->name,
                    'capacity' => $s->capacity,
                    'room' => $s->room,
                    'teacher_id' => $s->teacher_id,
                    'teacher_name' => $s->teacher ? $s->teacher->name : null,
                    'is_active' => (bool) $s->is_active,
                    'students' => $enrolled,
                    // Surfaced so a section that is over capacity is visible
                    // before somebody enrols the next student into it.
                    'is_full' => $s->capacity ? $enrolled >= $s->capacity : false,
                ];
            })->values(),
        ]);
    }

    public function storeSection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SchoolClass::class);

        $request->validate($this->sectionRules());

        $exists = ClassSection::whereNull('deleted_at')
            ->where('class_id', $request->class_id)->where('name', $request->name)->exists();
        if ($exists) {
            return response()->json(['message' => 'That section already exists in this class.'], 422);
        }

        ClassSection::create($this->sectionPayload($request));

        return response()->json(['success' => true]);
    }

    public function updateSection(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', SchoolClass::class);

        $request->validate($this->sectionRules());
        $section = ClassSection::whereNull('deleted_at')->findOrFail($id);

        $clash = ClassSection::whereNull('deleted_at')
            ->where('class_id', $request->class_id)->where('name', $request->name)
            ->where('id', '!=', $section->id)->exists();
        if ($clash) {
            return response()->json(['message' => 'That section already exists in this class.'], 422);
        }

        $section->update($this->sectionPayload($request));

        return response()->json(['success' => true]);
    }

    public function destroySection(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SchoolClass::class);

        $section = ClassSection::whereNull('deleted_at')->findOrFail($id);

        $enrolled = StudentEnrollment::whereNull('deleted_at')->where('section_id', $section->id)->count();
        if ($enrolled) {
            return response()->json([
                'message' => "This section holds {$enrolled} student(s). Move them first.",
            ], 422);
        }

        $section->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Subjects
    // ------------------------------------------------------------------

    public function subjects(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SchoolClass::class);

        $subjects = Subject::with('schoolClass')->whereNull('deleted_at')
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($inner) use ($request) {
                    $inner->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%");
                });
            })
            ->orderBy('class_id')->orderBy('name')->get();

        return response()->json([
            'totalRows' => $subjects->count(),
            'subjects' => $subjects->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'class_id' => $s->class_id,
                'class_name' => $s->schoolClass ? $s->schoolClass->name : null,
                'type' => $s->type,
                'credit' => (float) $s->credit,
                'pass_mark' => (int) $s->pass_mark,
                'is_active' => (bool) $s->is_active,
            ])->values(),
        ]);
    }

    public function storeSubject(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SchoolClass::class);

        $request->validate($this->subjectRules());
        Subject::create($this->subjectPayload($request));

        return response()->json(['success' => true]);
    }

    public function updateSubject(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', SchoolClass::class);

        $request->validate($this->subjectRules());
        Subject::whereNull('deleted_at')->findOrFail($id)->update($this->subjectPayload($request));

        return response()->json(['success' => true]);
    }

    public function destroySubject(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SchoolClass::class);

        Subject::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Rules / payloads
    // ------------------------------------------------------------------

    private function yearRules()
    {
        return [
            'name' => 'required|string|max:100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ];
    }

    private function yearPayload(Request $request)
    {
        return [
            'name' => $request->name,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_current' => $request->boolean('is_current'),
            'is_locked' => $request->boolean('is_locked'),
        ];
    }

    private function classRules()
    {
        return [
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:32',
            'level' => 'nullable|integer|min:0|max:1000',
        ];
    }

    private function classPayload(Request $request)
    {
        return [
            'name' => $request->name,
            'code' => $request->code,
            'level' => $request->level ?: 0,
            'description' => $request->description,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function sectionRules()
    {
        return [
            'class_id' => 'required|exists:school_classes,id',
            'name' => 'required|string|max:50',
            'capacity' => 'nullable|integer|min:1|max:500',
            'teacher_id' => 'nullable|exists:teachers,id',
        ];
    }

    private function sectionPayload(Request $request)
    {
        return [
            'class_id' => $request->class_id,
            'name' => $request->name,
            'capacity' => $request->capacity ?: null,
            'room' => $request->room,
            'teacher_id' => $request->teacher_id ?: null,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function subjectRules()
    {
        return [
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:32',
            'class_id' => 'nullable|exists:school_classes,id',
            'type' => 'required|in:core,elective,optional',
            'credit' => 'nullable|numeric|min:0|max:100',
            'pass_mark' => 'nullable|integer|min:0|max:100',
        ];
    }

    private function subjectPayload(Request $request)
    {
        return [
            'name' => $request->name,
            'code' => $request->code,
            'class_id' => $request->class_id ?: null,
            'type' => $request->type,
            'credit' => $request->credit ?: 1,
            'pass_mark' => $request->pass_mark ?: 40,
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
