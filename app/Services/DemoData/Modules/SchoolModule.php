<?php

namespace App\Services\DemoData\Modules;

use App\Models\ExamResult;
use App\Models\SchoolInvoice;
use App\Models\SchoolPayment;
use App\Models\Student;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;

/**
 * School examples: the current academic year, classes with sections, subjects,
 * teachers (as section class-teachers), students enrolled for the year
 * (company-scoped), a published term exam with marks and a scheduled one,
 * fee structures and one fee invoice per student with some payments.
 *
 * Only students and enrollments carry company_id; years, classes, subjects,
 * teachers and exams are install-wide, so they are part of dataTables():
 * examples are only added while the school is empty.
 */
class SchoolModule extends DemoModule
{
    private const CLASSES = [['Grade 1', 'G1', 1], ['Grade 2', 'G2', 2], ['Grade 3', 'G3', 3]];
    private const SUBJECTS = [
        ['Mathematics', 'MATH', 'core', 2.0],
        ['English Language', 'ENG', 'core', 2.0],
        ['Science', 'SCI', 'core', 1.5],
        ['Social Studies', 'SOC', 'core', 1.0],
        ['Art & Craft', 'ART', 'elective', 0.5],
    ];
    private const TEACHERS = [
        ['Mathematics', 'B.Sc. Mathematics, B.Ed.'],
        ['English', 'B.A. English, B.Ed.'],
        ['Science', 'B.Sc. Biology, B.Ed.'],
        ['Social Studies', 'B.A. History, B.Ed.'],
        ['Art', 'B.F.A.'],
    ];
    private const FEMALE = ['Sarah', 'Fatima', 'Aisha', 'Nadia', 'Leila', 'Julia', 'Emma'];

    public function key(): string
    {
        return 'school';
    }

    public function label(): string
    {
        return 'School (Academic year, Classes, Subjects, Teachers, Students, Exams, Fees)';
    }

    public function dataTables(): array
    {
        return ['students', 'academic_years', 'school_classes', 'subjects', 'teachers'];
    }

    public function requiredTables(): array
    {
        return ['students', 'academic_years', 'school_classes', 'class_sections', 'subjects', 'teachers',
            'student_enrollments', 'exams', 'exam_subjects', 'exam_results', 'fee_structures',
            'school_invoices', 'school_invoice_items', 'school_payments'];
    }

    public function generate(DemoContext $ctx): array
    {
        // ---- Academic year (Sep -> Jun around today)
        $startYear = now()->month >= 9 ? now()->year : now()->year - 1;
        $yearStart = now()->setDate($startYear, 9, 1)->startOfDay();
        $yearId = $ctx->insert('academic_years', [
            'name' => $startYear.'-'.($startYear + 1),
            'start_date' => $yearStart->toDateString(),
            'end_date' => now()->setDate($startYear + 1, 6, 30)->toDateString(),
            'is_current' => 1,
            'is_locked' => 0,
        ]);

        // ---- Teachers
        $teacherIds = [];
        foreach (self::TEACHERS as $i => [$spec, $qual]) {
            [$first, $last] = $ctx->personName();
            $teacherIds[] = $ctx->insert('teachers', [
                'employee_code' => 'TCH-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT).random_int(10, 99),
                'name' => "$first $last",
                'gender' => in_array($first, self::FEMALE, true) ? 'female' : 'male',
                'phone' => '555'.random_int(1000000, 9999999),
                'email' => strtolower("$first.$last.teacher".random_int(1000, 9999).'@example.com'),
                'qualification' => $qual,
                'specialization' => $spec,
                'joining_date' => now()->subYears(random_int(1, 8))->toDateString(),
                'salary' => $ctx->pick([2800, 3100, 3400, 3700]),
                'address' => random_int(1, 300).' Demo Street',
                'image' => null,
                'notes' => 'Demo data',
                'is_active' => 1,
            ]);
        }

        // ---- Classes & sections (Grade 1 has A and B)
        $classes = [];
        $sectionCount = 0;
        foreach (self::CLASSES as $ci => [$name, $code, $level]) {
            $classId = $ctx->insert('school_classes', [
                'name' => $name, 'code' => $code, 'level' => $level,
                'description' => 'Demo data', 'is_active' => 1,
            ]);
            $sections = [];
            foreach ($ci === 0 ? ['A', 'B'] : ['A'] as $si => $sec) {
                $sections[] = $ctx->insert('class_sections', [
                    'class_id' => $classId,
                    'name' => $sec,
                    'capacity' => 30,
                    'room' => 'Room '.($level * 100 + $si + 1),
                    'teacher_id' => $teacherIds[($ci + $si) % count($teacherIds)],
                    'is_active' => 1,
                ]);
                $sectionCount++;
            }
            $classes[] = ['id' => $classId, 'sections' => $sections, 'level' => $level];
        }

        // ---- Subjects (school-wide)
        $subjects = [];
        foreach (self::SUBJECTS as [$name, $code, $type, $credit]) {
            $subjects[] = $ctx->insert('subjects', [
                'name' => $name, 'code' => $code, 'class_id' => null, 'type' => $type,
                'credit' => $credit, 'pass_mark' => 40, 'is_active' => 1,
            ]);
        }

        // ---- Students + enrollments (3 / 3 / 2 across the grades)
        $students = [];
        $classOf = [0, 0, 0, 1, 1, 1, 2, 2];
        foreach ($classOf as $n => $ci) {
            [$first, $last] = $ctx->personName();
            [$gFirst] = $ctx->personName();
            $class = $classes[$ci];
            $sectionId = $class['sections'][$n % count($class['sections'])];
            $studentId = $ctx->insert('students', [
                'admission_number' => Student::generateAdmissionNumber(),
                'name' => "$first $last",
                'gender' => in_array($first, self::FEMALE, true) ? 'female' : 'male',
                'date_of_birth' => now()->subYears(5 + $class['level'])->subDays(random_int(0, 360))->toDateString(),
                'admission_date' => $yearStart->copy()->subYears(random_int(0, $class['level'] - 1))->toDateString(),
                'blood_group' => $ctx->pick(['A+', 'B+', 'O+', 'AB+', 'O-']),
                'address' => random_int(1, 300).' Demo Street',
                'city' => 'Demo City',
                'guardian_name' => "$gFirst $last",
                'guardian_relation' => $ctx->pick(['Father', 'Mother', 'Guardian']),
                'guardian_phone' => '555'.random_int(1000000, 9999999),
                'guardian_email' => strtolower("$gFirst.$last.parent".random_int(1000, 9999).'@example.com'),
                'guardian_occupation' => $ctx->pick(['Engineer', 'Teacher', 'Nurse', 'Accountant', 'Shop owner']),
                'image' => null,
                'notes' => 'Demo data',
                'status' => 'active',
            ]);
            $ctx->insert('student_enrollments', [
                'student_id' => $studentId,
                'academic_year_id' => $yearId,
                'class_id' => $class['id'],
                'section_id' => $sectionId,
                'roll_number' => (string) ($n + 1),
                'enrolled_on' => $yearStart->toDateString(),
                'status' => 'active',
                'notes' => null,
            ]);
            $students[] = ['id' => $studentId, 'class' => $ci];
        }

        // ---- Exams: Term 1 published with marks, Term 2 scheduled.
        // Term 1 sat ~2 weeks ago when the year is far enough along, so marks exist.
        $term1Start = now()->subDays(14)->startOfDay();
        if ($term1Start->lt($yearStart->copy()->addDays(7))) {
            $term1Start = $yearStart->copy()->addWeeks(9);
        }
        $term1Past = $term1Start->lt(now()->subDays(7));
        $exam1 = $ctx->insert('exams', [
            'academic_year_id' => $yearId, 'name' => 'Term 1 Examination', 'term' => 'term_1',
            'start_date' => $term1Start->toDateString(), 'end_date' => $term1Start->copy()->addDays(4)->toDateString(),
            'status' => $term1Past ? 'published' : 'scheduled', 'notes' => 'Demo data',
        ]);
        $term2Start = $term1Start->copy()->addWeeks(12);
        $exam2 = $ctx->insert('exams', [
            'academic_year_id' => $yearId, 'name' => 'Term 2 Examination', 'term' => 'term_2',
            'start_date' => $term2Start->toDateString(), 'end_date' => $term2Start->copy()->addDays(4)->toDateString(),
            'status' => $term2Start->lt(now()) ? 'completed' : 'scheduled', 'notes' => 'Demo data',
        ]);

        $examSubjects = $results = 0;
        foreach ([$exam1 => $term1Start, $exam2 => $term2Start] as $examId => $examStart) {
            foreach ($classes as $ci => $class) {
                foreach (array_slice($subjects, 0, 3) as $si => $subjectId) {
                    $esId = $ctx->insert('exam_subjects', [
                        'exam_id' => $examId,
                        'class_id' => $class['id'],
                        'subject_id' => $subjectId,
                        'exam_date' => $examStart->copy()->addDays($si)->toDateString(),
                        'start_time' => '09:00:00',
                        'duration_minutes' => 90,
                        'max_marks' => 100,
                        'pass_marks' => 40,
                        'room' => 'Hall '.($ci + 1),
                    ]);
                    $examSubjects++;
                    // Marks only for the first (published) exam.
                    if ($examId !== $exam1 || ! $term1Past) {
                        continue;
                    }
                    foreach ($students as $n => $s) {
                        if ($s['class'] !== $ci) {
                            continue;
                        }
                        $absent = $n === 4 && $si === 2;
                        $marks = $absent ? null : random_int(35, 98);
                        $ctx->insert('exam_results', [
                            'exam_subject_id' => $esId,
                            'student_id' => $s['id'],
                            'marks' => $marks,
                            'is_absent' => $absent ? 1 : 0,
                            'grade' => $absent ? null : ExamResult::gradeFor($marks),
                            'remarks' => $absent ? 'Absent (medical)' : null,
                            'entered_by' => $ctx->userId,
                        ]);
                        $results++;
                    }
                }
            }
        }

        // ---- Fees
        $fees = [];
        foreach ([['Tuition Fee', 'termly', 450, false], ['Transport Fee', 'termly', 120, true],
            ['Library & Activity Fee', 'yearly', 60, false]] as [$name, $freq, $amount, $optional]) {
            $fees[] = [
                'id' => $ctx->insert('fee_structures', [
                    'academic_year_id' => $yearId, 'class_id' => null, 'name' => $name,
                    'frequency' => $freq, 'amount' => $amount,
                    'due_date' => $yearStart->copy()->addDays(30)->toDateString(),
                    'is_optional' => $optional ? 1 : 0, 'is_active' => 1, 'description' => 'Demo data',
                ]),
                'name' => $name, 'amount' => $amount, 'optional' => $optional,
            ];
        }

        $invoices = $items = $payments = 0;
        $invoiceDate = $yearStart->copy()->addDays(3);
        foreach ($students as $n => $s) {
            $lines = array_values(array_filter($fees, fn ($f) => ! $f['optional'] || $n % 2 === 0));
            $total = array_sum(array_column($lines, 'amount'));
            $roll = $n % 4; // 0,1 paid -- 2 partial -- 3 unpaid
            $paid = $roll <= 1 ? $total : ($roll === 2 ? round($total / 2, 2) : 0);
            $invoiceId = $ctx->insert('school_invoices', [
                'reference' => SchoolInvoice::nextReference('FEE'),
                'student_id' => $s['id'],
                'academic_year_id' => $yearId,
                'class_id' => $classes[$s['class']]['id'],
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $invoiceDate->copy()->addDays(30)->toDateString(),
                'subtotal' => $total, 'discount' => 0, 'total' => $total, 'paid' => $paid,
                'status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'notes' => 'Demo data',
                'created_by' => $ctx->userId,
            ]);
            $invoices++;
            foreach ($lines as $f) {
                $ctx->insert('school_invoice_items', [
                    'invoice_id' => $invoiceId, 'fee_structure_id' => $f['id'], 'description' => $f['name'],
                    'quantity' => 1, 'unit_price' => $f['amount'], 'total' => $f['amount'],
                ]);
                $items++;
            }
            if ($paid > 0) {
                $ctx->insert('school_payments', [
                    'reference' => SchoolPayment::nextReference('SPY'),
                    'invoice_id' => $invoiceId,
                    'student_id' => $s['id'],
                    'paid_on' => $invoiceDate->copy()->addDays(random_int(1, 20))->toDateString(),
                    'amount' => $paid,
                    'method' => $ctx->pick(['cash', 'bank_transfer', 'card']),
                    'notes' => 'Demo data',
                    'created_by' => $ctx->userId,
                ]);
                $payments++;
            }
        }

        return [
            'Academic years' => 1,
            'Classes' => count($classes),
            'Sections' => $sectionCount,
            'Subjects' => count($subjects),
            'Teachers' => count($teacherIds),
            'Students' => count($students),
            'Enrollments' => count($students),
            'Exams' => 2,
            'Exam subjects' => $examSubjects,
            'Exam results' => $results,
            'Fee structures' => count($fees),
            'Fee invoices' => $invoices,
            'Invoice items' => $items,
            'Payments' => $payments,
        ];
    }
}
