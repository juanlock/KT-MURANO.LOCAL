<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A school demo that is alive every day (user's request, 2026-11-02): the demo
 * made by SchoolModule has the daily register up to the day it was generated.
 * For a company with school demo data:
 *  - attendance(): every school day after the last register, up to yesterday and
 *    within the school year, gets its register for the demo students (today stays
 *    empty so "Take register" can be tried), same profiles as the demo (about 1 in
 *    9 often absent, 1 in 13 often late);
 *  - exams(): a demo exam whose dates have come is "ongoing".
 * Only demo rows; tagged in demo_records so Remove takes them too. Safe to run often.
 */
class SchoolDemoDay
{
    private const MODULE = 'school';

    public static function run(int $companyId): array
    {
        return ['attendance' => self::attendance($companyId), 'exams' => self::exams($companyId)];
    }

    private static function ids(int $cid, string $table): array
    {
        return DB::table('demo_records')->where('company_id', $cid)->where('module', self::MODULE)->where('record_type', $table)
            ->pluck('record_id')->map(fn ($v) => (int) $v)->all();
    }

    public static function attendance(int $cid): int
    {
        $enrollments = DB::table('student_enrollments')->whereIn('id', self::ids($cid, 'student_enrollments'))->whereNull('deleted_at')
            ->get(['id', 'student_id', 'class_id', 'section_id', 'academic_year_id']);
        if ($enrollments->isEmpty()) {
            return 0;
        }
        $year = DB::table('academic_years')->where('id', $enrollments->first()->academic_year_id)->first(['start_date', 'end_date']);
        $last = DB::table('student_attendances')->where('company_id', $cid)->whereIn('enrollment_id', $enrollments->pluck('id'))->max('attendance_date');
        if (! $year || ! $last) {
            return 0;
        }
        $markedBy = DB::table('student_attendances')->where('company_id', $cid)->where('attendance_date', $last)->value('marked_by');
        $until = now()->startOfDay()->min(\Carbon\Carbon::parse($year->end_date)->addDay());
        $made = 0;
        for ($day = \Carbon\Carbon::parse($last)->addDay(); $day->lt($until); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            $date = $day->toDateString();
            DB::transaction(function () use ($enrollments, $cid, $date, $markedBy, &$made) {
                $rows = [];
                foreach ($enrollments as $e) {
                    $n = (int) $e->student_id;
                    $r = random_int(1, 100);
                    [$abs, $exc, $late] = $n % 9 === 4 ? [16, 20, 28] : ($n % 13 === 6 ? [3, 5, 25] : [3, 5, 9]);
                    $rows[] = ['company_id' => $cid, 'student_id' => $e->student_id, 'enrollment_id' => $e->id, 'class_id' => $e->class_id,
                        'section_id' => $e->section_id, 'subject_id' => null, 'attendance_date' => $date,
                        'status' => $r <= $abs ? 'absent' : ($r <= $exc ? 'excused' : ($r <= $late ? 'late' : 'present')),
                        'remarks' => null, 'marked_by' => $markedBy, 'created_at' => now(), 'updated_at' => now()];
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('student_attendances')->insert($chunk);
                }
                // Tagged as demo rows (Remove / reset take them too).
                $ids = DB::table('student_attendances')->where('company_id', $cid)->where('attendance_date', $date)
                    ->whereIn('enrollment_id', $enrollments->pluck('id'))->pluck('id');
                foreach ($ids->chunk(500) as $chunk) {
                    DB::table('demo_records')->insert($chunk->map(fn ($id) => ['company_id' => $cid, 'module' => self::MODULE,
                        'record_type' => 'student_attendances', 'record_id' => $id, 'created_at' => now(), 'updated_at' => now()])->values()->all());
                }
                $made += count($rows);
            });
        }

        return $made;
    }

    public static function exams(int $cid): int
    {
        $ids = self::ids($cid, 'exams');

        return $ids ? DB::table('exams')->whereIn('id', $ids)->where('status', 'scheduled')
            ->whereDate('start_date', '<=', now()->toDateString())->update(['status' => 'ongoing', 'updated_at' => now()]) : 0;
    }
}
