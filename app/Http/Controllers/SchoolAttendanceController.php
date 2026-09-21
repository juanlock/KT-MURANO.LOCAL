<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\StudentAttendance;
use App\Models\StudentEnrollment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The attendance register.
 *
 * Taking a register is a BULK operation, not a row at a time: the teacher opens
 * a class for a date, everyone defaults to present, and only the exceptions get
 * changed. Saving upserts on (student, date, subject) so re-opening a register
 * and correcting it updates rather than duplicating — which the unique index
 * guarantees even if two tabs save at once.
 *
 * subject_id null = the whole-day register; set = a per-period one. The rate
 * calculations only ever count whole-day rows, so a school using both does not
 * count the same day six times.
 */
class SchoolAttendanceController extends Controller
{
    /** The class register for a date: every enrolled student, with any mark. */
    public function register(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StudentAttendance::class);

        $request->validate([
            'class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:class_sections,id',
            'subject_id' => 'nullable|exists:subjects,id',
            'date' => 'nullable|date',
        ]);

        $date = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();
        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $enrollments = StudentEnrollment::with('student')
            ->whereNull('deleted_at')
            ->where('class_id', $request->class_id)
            ->where('status', 'active')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->get()
            ->filter(fn ($e) => $e->student !== null)
            ->sortBy(fn ($e) => [$e->roll_number ?: 'zzz', $e->student->name]);

        $marks = StudentAttendance::whereDate('attendance_date', $date->toDateString())
            ->when($request->filled('subject_id'),
                fn ($q) => $q->where('subject_id', $request->subject_id),
                fn ($q) => $q->whereNull('subject_id'))
            ->whereIn('student_id', $enrollments->pluck('student_id'))
            ->get()->keyBy('student_id');

        return response()->json([
            'date' => $date->toDateString(),
            'already_marked' => $marks->isNotEmpty(),
            'students' => $enrollments->values()->map(function ($e) use ($marks) {
                $mark = $marks->get($e->student_id);

                return [
                    'student_id' => $e->student_id,
                    'enrollment_id' => $e->id,
                    'name' => $e->student->name,
                    'admission_number' => $e->student->admission_number,
                    'roll_number' => $e->roll_number,
                    'image_url' => $e->student->image ? asset('images/students/' . $e->student->image) : null,
                    // Unmarked students default to present — the register is a
                    // list of exceptions, not a list of everyone who turned up.
                    'status' => $mark ? $mark->status : 'present',
                    'remarks' => $mark ? $mark->remarks : null,
                ];
            })->values(),
        ]);
    }

    /** Save a whole register in one transaction. */
    public function save(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', StudentAttendance::class);

        $request->validate([
            'class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:class_sections,id',
            'subject_id' => 'nullable|exists:subjects,id',
            'date' => 'required|date',
            'entries' => 'required|array|min:1',
            'entries.*.student_id' => 'required|integer',
            'entries.*.status' => 'required|in:present,absent,late,excused,half_day',
        ]);

        $date = Carbon::parse($request->date)->toDateString();
        if (Carbon::parse($date)->isFuture()) {
            return response()->json(['message' => 'You cannot take a register for a future date.'], 422);
        }

        $userId = optional($request->user('api'))->id;
        $saved = 0;

        DB::transaction(function () use ($request, $date, $userId, &$saved) {
            foreach ($request->entries as $entry) {
                StudentAttendance::updateOrCreate(
                    [
                        'student_id' => $entry['student_id'],
                        'attendance_date' => $date,
                        'subject_id' => $request->subject_id ?: null,
                    ],
                    [
                        'enrollment_id' => $entry['enrollment_id'] ?? null,
                        'class_id' => $request->class_id,
                        'section_id' => $request->section_id ?: null,
                        'status' => $entry['status'],
                        'remarks' => $entry['remarks'] ?? null,
                        'marked_by' => $userId,
                    ]
                );
                $saved++;
            }
        });

        return response()->json(['success' => true, 'saved' => $saved]);
    }

    /** Day-by-day summary for a class over a range, for the monthly sheet. */
    public function summary(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StudentAttendance::class);

        $request->validate([
            'class_id' => 'nullable|exists:school_classes,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $from = $request->filled('start_date') ? $request->start_date : Carbon::today()->startOfMonth()->toDateString();
        $to = $request->filled('end_date') ? $request->end_date : Carbon::today()->toDateString();

        $rows = StudentAttendance::whereNull('subject_id')
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->select('attendance_date', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('attendance_date', 'status')
            ->get();

        $days = [];
        foreach ($rows->groupBy(fn ($r) => (string) $r->attendance_date) as $day => $group) {
            $present = (int) $group->whereIn('status', StudentAttendance::presentStatuses())->sum('aggregate');
            $total = (int) $group->sum('aggregate');
            $days[] = [
                'd' => substr((string) $day, 0, 10),
                'present' => $present,
                'absent' => (int) $group->where('status', 'absent')->sum('aggregate'),
                'total' => $total,
                'rate' => $total ? round(($present / $total) * 100, 1) : 0,
            ];
        }

        usort($days, fn ($a, $b) => strcmp($a['d'], $b['d']));

        return response()->json(['days' => $days]);
    }
}
