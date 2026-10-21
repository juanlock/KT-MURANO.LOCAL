<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * School Management multicompany (2026-10-20, same as Hospital): only students
 * and enrollments had a company; years, classes, sections, subjects, teachers,
 * timetable, attendance, exams, marks, fees, invoices and payments were shared
 * by every company -- Colegio Bicentenario showed Vanclem's demo school and the
 * demo generator refused to make its own. Adds company_id to each (the models
 * filter by the acting company: App\Models\Concerns\BelongsToActingCompany) and
 * fills it:
 *  1. rows tagged in demo_records -> that demo's company;
 *  2. a student's rows -> the student's company;
 *  3. lines follow their document; masters (years, classes, sections, subjects,
 *     teachers, exams, fee structures, timetable) -> the company of the rows that use them;
 * anything still unknown stays empty (only the SuperAdmin sees it). Safe to run twice.
 */
return new class extends Migration
{
    private const TABLES = [
        'academic_years', 'school_classes', 'class_sections', 'subjects', 'teachers', 'timetable_slots',
        'student_attendances', 'exams', 'exam_subjects', 'exam_results', 'fee_structures',
        'school_invoices', 'school_invoice_items', 'school_payments',
    ];

    /** Rows of a student. */
    private const BY_STUDENT = ['student_attendances', 'exam_results', 'school_invoices', 'school_payments'];

    /** [table, column pointing to the master, master] -- the master takes the user's company. */
    private const USES = [
        ['school_invoice_items', 'invoice_id', 'school_invoices', true],   // line <- document
        ['exam_subjects', 'exam_id', 'exams', true],                       // paper <- exam
        ['exam_results', 'exam_subject_id', 'exam_subjects', false],       // paper <- its marks
        ['exam_subjects', 'exam_id', 'exams', false],                      // exam <- its papers
        ['student_enrollments', 'academic_year_id', 'academic_years', false],
        ['student_enrollments', 'class_id', 'school_classes', false],
        ['student_enrollments', 'section_id', 'class_sections', false],
        ['class_sections', 'class_id', 'school_classes', false],
        ['class_sections', 'teacher_id', 'teachers', false],
        ['exam_subjects', 'subject_id', 'subjects', false],
        ['exams', 'academic_year_id', 'academic_years', false],
        ['school_invoice_items', 'fee_structure_id', 'fee_structures', false],
        ['fee_structures', 'academic_year_id', 'academic_years', false],
    ];

    public function up(): void
    {
        $tables = array_values(array_filter(self::TABLES, fn ($t) => Schema::hasTable($t)));
        foreach ($tables as $t) {
            if (! Schema::hasColumn($t, 'company_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedInteger('company_id')->nullable()->after('id')->index();
                });
            }
        }
        $has = fn ($t) => Schema::hasTable($t) && Schema::hasColumn($t, 'company_id');

        // 1. Demo rows.
        if (Schema::hasTable('demo_records')) {
            foreach ($tables as $t) {
                foreach (DB::table('demo_records')->where('record_type', $t)->select('company_id', 'record_id')->get()->groupBy('company_id') as $cid => $rows) {
                    foreach ($rows->pluck('record_id')->chunk(500) as $ids) {
                        DB::table($t)->whereIn('id', $ids->all())->whereNull('company_id')->update(['company_id' => (int) $cid]);
                    }
                }
            }
        }

        // 2. A student's rows.
        if ($has('students')) {
            foreach (self::BY_STUDENT as $t) {
                if ($has($t) && Schema::hasColumn($t, 'student_id')) {
                    DB::statement("UPDATE `$t` x JOIN `students` s ON s.id = x.student_id SET x.company_id = s.company_id WHERE x.company_id IS NULL AND s.company_id IS NOT NULL");
                }
            }
        }

        // 3. Lines from their document, masters from the rows that use them (a few passes).
        for ($pass = 0; $pass < 3; $pass++) {
            foreach (self::USES as [$user, $col, $master, $childFromParent]) {
                if (! $has($user) || ! $has($master) || ! Schema::hasColumn($user, $col)) {
                    continue;
                }
                if ($childFromParent) {
                    DB::statement("UPDATE `$user` c JOIN `$master` p ON p.id = c.`$col` SET c.company_id = p.company_id WHERE c.company_id IS NULL AND p.company_id IS NOT NULL");
                } else {
                    DB::statement("UPDATE `$master` m JOIN `$user` u ON u.`$col` = m.id SET m.company_id = u.company_id WHERE m.company_id IS NULL AND u.company_id IS NOT NULL");
                }
            }
            // Timetable follows its section; a section with no company follows its class.
            if ($has('timetable_slots') && $has('class_sections')) {
                DB::statement('UPDATE `timetable_slots` x JOIN `class_sections` s ON s.id = x.section_id SET x.company_id = s.company_id WHERE x.company_id IS NULL AND s.company_id IS NOT NULL');
                DB::statement('UPDATE `class_sections` s JOIN `school_classes` c ON c.id = s.class_id SET s.company_id = c.company_id WHERE s.company_id IS NULL AND c.company_id IS NOT NULL');
            }
            if ($has('timetable_slots')) {
                foreach ([['subject_id', 'subjects'], ['teacher_id', 'teachers']] as [$col, $master]) {
                    if ($has($master)) {
                        DB::statement("UPDATE `$master` m JOIN `timetable_slots` u ON u.`$col` = m.id SET m.company_id = u.company_id WHERE m.company_id IS NULL AND u.company_id IS NOT NULL");
                    }
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'company_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropIndex(['company_id']);
                    $table->dropColumn('company_id');
                });
            }
        }
    }
};
