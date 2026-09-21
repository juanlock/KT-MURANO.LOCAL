<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\TimetableSlot;
use Illuminate\Http\Request;

/**
 * The class timetable.
 *
 * Two clashes are refused outright, because both mean the timetable is lying:
 *   - a SECTION cannot sit two subjects at the same time;
 *   - a TEACHER cannot be in two rooms at the same time.
 * Both are checked across the whole year, not just the class being edited —
 * the second one is only visible if you look outside the current screen, which
 * is exactly why people miss it when scheduling by hand.
 */
class SchoolTimetableController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', TimetableSlot::class);

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $slots = TimetableSlot::with('subject', 'teacher', 'schoolClass', 'section')
            ->whereNull('deleted_at')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->teacher_id))
            ->orderBy('start_time')->get();

        $byDay = [];
        foreach (TimetableSlot::DAYS as $day) {
            $byDay[$day] = $slots->where('day_of_week', $day)->map(fn ($s) => $this->present($s))->values();
        }

        return response()->json([
            'days' => $byDay,
            'slots' => $slots->map(fn ($s) => $this->present($s))->values(),
            'total' => $slots->count(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', TimetableSlot::class);

        $request->validate($this->rules());

        if ($clash = $this->findClash($request)) {
            return response()->json(['message' => $clash], 422);
        }

        TimetableSlot::create($this->payload($request));

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', TimetableSlot::class);

        $request->validate($this->rules());
        $slot = TimetableSlot::whereNull('deleted_at')->findOrFail($id);

        if ($clash = $this->findClash($request, $slot->id)) {
            return response()->json(['message' => $clash], 422);
        }

        $slot->update($this->payload($request));

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', TimetableSlot::class);

        TimetableSlot::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Returns a human message describing the clash, or null when the slot is
     * free. Overlap is [start, end) on both sides, so back-to-back periods are
     * fine but a one-minute overlap is not.
     */
    private function findClash(Request $request, $exceptId = null)
    {
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;
        $start = $request->start_time;
        $end = $request->end_time;

        $overlapping = TimetableSlot::with('subject', 'teacher', 'schoolClass', 'section')
            ->whereNull('deleted_at')
            ->where('academic_year_id', $yearId)
            ->where('day_of_week', $request->day_of_week)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->get();

        $sectionClash = $overlapping->first(function ($slot) use ($request) {
            return (int) $slot->class_id === (int) $request->class_id
                && (int) $slot->section_id === (int) $request->section_id;
        });
        if ($sectionClash) {
            return 'That section already has ' . ($sectionClash->subject ? $sectionClash->subject->name : 'a lesson')
                . ' at ' . substr((string) $sectionClash->start_time, 0, 5) . '.';
        }

        if ($request->filled('teacher_id')) {
            $teacherClash = $overlapping->first(fn ($slot) => (int) $slot->teacher_id === (int) $request->teacher_id);
            if ($teacherClash) {
                $where = $teacherClash->schoolClass ? $teacherClash->schoolClass->name : 'another class';
                return 'That teacher is already teaching ' . $where
                    . ' at ' . substr((string) $teacherClash->start_time, 0, 5) . '.';
            }
        }

        return null;
    }

    private function present(TimetableSlot $s)
    {
        return [
            'id' => $s->id,
            'academic_year_id' => $s->academic_year_id,
            'class_id' => $s->class_id,
            'class_name' => $s->schoolClass ? $s->schoolClass->name : '',
            'section_id' => $s->section_id,
            'section_name' => $s->section ? $s->section->name : null,
            'subject_id' => $s->subject_id,
            'subject_name' => $s->subject ? $s->subject->name : '',
            'teacher_id' => $s->teacher_id,
            'teacher_name' => $s->teacher ? $s->teacher->name : null,
            'day_of_week' => $s->day_of_week,
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
            'room' => $s->room,
        ];
    }

    private function rules()
    {
        return [
            'class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:class_sections,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'day_of_week' => 'required|in:mon,tue,wed,thu,fri,sat,sun',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'academic_year_id' => $request->academic_year_id ?: optional(AcademicYear::current())->id,
            'class_id' => $request->class_id,
            'section_id' => $request->section_id ?: null,
            'subject_id' => $request->subject_id,
            'teacher_id' => $request->teacher_id ?: null,
            'day_of_week' => $request->day_of_week,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'room' => $request->room,
        ];
    }
}
