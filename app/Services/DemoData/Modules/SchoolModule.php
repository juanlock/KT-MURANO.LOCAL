<?php

namespace App\Services\DemoData\Modules;

use App\Models\ExamResult;
use App\Models\SchoolInvoice;
use App\Models\SchoolPayment;
use App\Models\Student;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * School examples -- a full working school (2026-10-20: the old package had 8
 * students and no attendance or timetable):
 *  - the current academic year (Sep -> Jun);
 *  - 4 grades x 2 sections (A / B) with their classroom and homeroom teacher;
 *  - 7 subjects and 14 teachers (two per subject: grades 1-2 and 3-4);
 *  - 6 students per section (48), each enrolled for the year with a roll number;
 *  - a weekly timetable for every section (Mon-Fri, 5 periods, no teacher clash);
 *  - the daily register from the start of the year to yesterday (school days);
 *  - Term 1 exam published with marks for every subject, Term 2 scheduled;
 *  - fee structures, one invoice per student (paid / partial / unpaid / overdue)
 *    and their payments.
 * Texts follow the company's language (Spanish or English).
 *
 * Only students and enrollments carry company_id; years, classes, subjects,
 * teachers and exams are install-wide, so they are part of dataTables():
 * examples are only added while the school is empty.
 */
class SchoolModule extends DemoModule
{
    private const GRADES = 4;
    private const PER_SECTION = 6;
    private const PERIODS = [['08:00', '08:45'], ['08:45', '09:30'], ['09:45', '10:30'], ['10:30', '11:15'], ['11:30', '12:15']];
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri'];

    /** [es, en] texts. */
    private const T = [
        'grade' => ['{n}.º grado', 'Grade {n}'],
        'room' => ['Aula {n}', 'Room {n}'],
        'hall' => ['Salón {n}', 'Hall {n}'],
        'demo' => ['Datos de ejemplo', 'Demo data'],
        'term1' => ['Examen del primer bimestre', 'Term 1 Examination'],
        'term2' => ['Examen del segundo bimestre', 'Term 2 Examination'],
        'absent_note' => ['Ausente (cita médica)', 'Absent (medical)'],
        'street' => ['Av. Los Próceres', 'Demo Street'],
        'city' => ['Lima', 'Demo City'],
        'late_note' => ['Llegó tarde', 'Arrived late'],
        'excused_note' => ['Justificado por el apoderado', 'Excused by the guardian'],
    ];

    /** [es name, en name, code, type, credit]. */
    private const SUBJECTS = [
        ['Matemática', 'Mathematics', 'MAT', 'core', 2.0],
        ['Comunicación', 'Language Arts', 'COM', 'core', 2.0],
        ['Ciencia y Tecnología', 'Science', 'CYT', 'core', 1.5],
        ['Personal Social', 'Social Studies', 'PSO', 'core', 1.0],
        ['Inglés', 'English', 'ING', 'core', 1.0],
        ['Arte y Cultura', 'Art', 'ART', 'elective', 0.5],
        ['Educación Física', 'Physical Education', 'EFI', 'elective', 0.5],
    ];

    /** Qualifications per subject [es, en]. */
    private const QUALIFICATIONS = [
        ['Lic. en Educación, especialidad Matemática', 'B.Ed. Mathematics'],
        ['Lic. en Educación, especialidad Lengua y Literatura', 'B.A. Literature, B.Ed.'],
        ['Lic. en Biología, Educación', 'B.Sc. Biology, B.Ed.'],
        ['Lic. en Historia y Geografía', 'B.A. History, B.Ed.'],
        ['Lic. en Idiomas, Inglés', 'B.A. English, TEFL'],
        ['Lic. en Educación Artística', 'B.F.A.'],
        ['Lic. en Educación Física', 'B.Sc. Physical Education'],
    ];

    private const FEES = [
        // [es, en, frequency, amount, optional]
        ['Pensión de enseñanza', 'Tuition Fee', 'termly', 450, false],
        ['Movilidad escolar', 'Transport Fee', 'termly', 120, true],
        ['Biblioteca y actividades', 'Library & Activity Fee', 'yearly', 60, false],
    ];

    private const ES_FEMALE = ['María', 'Lucía', 'Valentina', 'Camila', 'Sofía', 'Ximena', 'Daniela', 'Isabella', 'Mariana', 'Fernanda', 'Gabriela', 'Andrea'];
    private const ES_MALE = ['Mateo', 'Santiago', 'Sebastián', 'Diego', 'Alejandro', 'Nicolás', 'Gabriel', 'Adrián', 'Thiago', 'Leonardo', 'Joaquín', 'Rodrigo'];
    private const ES_LAST = ['García', 'Rodríguez', 'Quispe', 'Flores', 'Sánchez', 'Ramírez', 'Torres', 'Mendoza', 'Rojas', 'Vargas', 'Castillo', 'Huamán', 'Chávez', 'Díaz', 'Herrera', 'Salazar'];
    private const EN_FEMALE = ['Sarah', 'Emma', 'Olivia', 'Ava', 'Mia', 'Chloe', 'Grace', 'Lily', 'Ella', 'Zoe', 'Ruby', 'Alice'];
    private const EN_MALE = ['James', 'Liam', 'Noah', 'Ethan', 'Lucas', 'Henry', 'Jack', 'Leo', 'Oscar', 'Owen', 'Adam', 'Ryan'];
    private const EN_LAST = ['Smith', 'Johnson', 'Brown', 'Taylor', 'Wilson', 'Clark', 'Lewis', 'Walker', 'Hall', 'Young', 'King', 'Wright', 'Scott', 'Green', 'Baker', 'Adams'];

    private bool $es = false;

    public function key(): string
    {
        return 'school';
    }

    public function label(): string
    {
        return 'School (Academic year, Classes, Subjects, Teachers, Students, Enrolment, Timetable, Attendance, Exams, Fees)';
    }

    public function dataTables(): array
    {
        return ['students', 'academic_years', 'school_classes', 'subjects', 'teachers'];
    }

    public function requiredTables(): array
    {
        return ['students', 'academic_years', 'school_classes', 'class_sections', 'subjects', 'teachers',
            'student_enrollments', 'timetable_slots', 'student_attendances', 'exams', 'exam_subjects', 'exam_results',
            'fee_structures', 'school_invoices', 'school_invoice_items', 'school_payments'];
    }

    private function t(string $key, array $p = []): string
    {
        $text = self::T[$key][$this->es ? 0 : 1];

        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($p[$m[1]] ?? $m[0]), $text);
    }

    /** [first, last, gender] in the company's language. */
    private function person(DemoContext $ctx, ?string $gender = null): array
    {
        $gender = $gender ?: $ctx->pick(['male', 'female']);
        $first = $this->es ? ($gender === 'female' ? self::ES_FEMALE : self::ES_MALE) : ($gender === 'female' ? self::EN_FEMALE : self::EN_MALE);
        $last = $this->es ? self::ES_LAST : self::EN_LAST;
        // Spanish: two surnames.
        $surname = $this->es ? $ctx->pick($last).' '.$ctx->pick($last) : $ctx->pick($last);

        return [$ctx->pick($first), $surname, $gender];
    }

    private function slug(string $s): string
    {
        $s = strtolower(\Illuminate\Support\Str::ascii($s));

        return preg_replace('/[^a-z]+/', '.', trim($s));
    }

    public function generate(DemoContext $ctx): array
    {
        $lang = DB::table('settings')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->value('default_language');
        $this->es = str_starts_with((string) $lang, 'es');
        $demo = $this->t('demo');

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

        // ---- Subjects (school-wide) + two teachers each (grades 1-2 / 3-4)
        $subjects = [];
        $teacherOf = []; // [subjectIndex][0|1] => teacher id
        $teacherCount = 0;
        foreach (self::SUBJECTS as $si => [$es, $en, $code, $type, $credit]) {
            $subjects[$si] = $ctx->insert('subjects', [
                'name' => $this->es ? $es : $en, 'code' => $code, 'class_id' => null, 'type' => $type,
                'credit' => $credit, 'pass_mark' => $this->es ? 55 : 40, 'is_active' => 1, // % of the paper's max (11 / 20 = 55%)
            ]);
            foreach ([0, 1] as $half) {
                [$first, $last, $gender] = $this->person($ctx);
                $teacherOf[$si][$half] = $ctx->insert('teachers', [
                    'employee_code' => 'DOC-'.str_pad((string) (++$teacherCount), 3, '0', STR_PAD_LEFT),
                    'name' => "$first $last",
                    'gender' => $gender,
                    'phone' => '9'.random_int(10000000, 99999999),
                    'email' => $this->slug("$first $last").'.docente'.random_int(10, 99).'@example.com',
                    'qualification' => self::QUALIFICATIONS[$si][$this->es ? 0 : 1],
                    'specialization' => $this->es ? $es : $en,
                    'joining_date' => now()->subYears(random_int(1, 12))->toDateString(),
                    'salary' => $ctx->pick([2800, 3100, 3400, 3700]),
                    'address' => $this->t('street').' '.random_int(100, 999),
                    'image' => null,
                    'notes' => $demo,
                    'is_active' => 1,
                ]);
            }
        }

        // ---- Grades & sections (A / B), classroom + homeroom teacher
        $classes = [];
        $sections = []; // flat: [id, classIndex, level, name, room]
        for ($g = 1; $g <= self::GRADES; $g++) {
            $classId = $ctx->insert('school_classes', [
                'name' => $this->t('grade', ['n' => $g]), 'code' => 'G'.$g, 'level' => $g,
                'description' => $demo, 'is_active' => 1,
            ]);
            $classes[$g - 1] = ['id' => $classId, 'level' => $g];
            foreach (['A', 'B'] as $si => $sec) {
                $room = $this->t('room', ['n' => $g * 100 + $si + 1]);
                $k = count($sections);
                $sections[] = [
                    'id' => $ctx->insert('class_sections', [
                        'class_id' => $classId,
                        'name' => $sec,
                        'capacity' => 30,
                        'room' => $room,
                        // Homeroom: the Language Arts / Maths teacher of that half of the school.
                        'teacher_id' => $teacherOf[$si === 0 ? 1 : 0][$g <= 2 ? 0 : 1],
                        'is_active' => 1,
                    ]),
                    'class' => $g - 1, 'level' => $g, 'room' => $room, 'k' => $k,
                ];
            }
        }

        // ---- Students + enrollments
        $students = [];
        $n = 0;
        foreach ($sections as $sec) {
            $class = $classes[$sec['class']];
            for ($i = 0; $i < self::PER_SECTION; $i++) {
                [$first, $last, $gender] = $this->person($ctx, $i % 2 ? 'female' : 'male');
                [$gFirst, , $gGender] = $this->person($ctx);
                $lastOnly = explode(' ', $last)[0];
                $relation = $gGender === 'female' ? ($this->es ? 'Madre' : 'Mother') : ($this->es ? 'Padre' : 'Father');
                $studentId = $ctx->insert('students', [
                    'admission_number' => Student::generateAdmissionNumber(),
                    'name' => "$first $last",
                    'gender' => $gender,
                    'date_of_birth' => now()->subYears(5 + $sec['level'])->subDays(random_int(0, 360))->toDateString(),
                    'admission_date' => $yearStart->copy()->subYears(random_int(0, $sec['level'] - 1))->toDateString(),
                    'blood_group' => $ctx->pick(['A+', 'B+', 'O+', 'O+', 'AB+', 'O-']),
                    'address' => $this->t('street').' '.random_int(100, 999),
                    'city' => $this->t('city'),
                    'guardian_name' => "$gFirst $lastOnly",
                    'guardian_relation' => $relation,
                    'guardian_phone' => '9'.random_int(10000000, 99999999),
                    'guardian_email' => $this->slug("$gFirst $lastOnly").'.apoderado'.random_int(100, 999).'@example.com',
                    'guardian_occupation' => $this->es
                        ? $ctx->pick(['Ingeniero(a)', 'Docente', 'Enfermero(a)', 'Contador(a)', 'Comerciante', 'Abogado(a)'])
                        : $ctx->pick(['Engineer', 'Teacher', 'Nurse', 'Accountant', 'Shop owner', 'Lawyer']),
                    'image' => null,
                    'notes' => $demo,
                    'status' => 'active',
                ]);
                $enrollmentId = $ctx->insert('student_enrollments', [
                    'student_id' => $studentId,
                    'academic_year_id' => $yearId,
                    'class_id' => $class['id'],
                    'section_id' => $sec['id'],
                    'roll_number' => (string) ($i + 1),
                    'enrolled_on' => $yearStart->toDateString(),
                    'status' => 'active',
                    'notes' => null,
                ]);
                $students[] = ['id' => $studentId, 'enrollment' => $enrollmentId, 'class' => $sec['class'],
                    'class_id' => $class['id'], 'section_id' => $sec['id'], 'n' => $n++];
            }
        }

        // ---- Timetable: every section Mon-Fri, 5 periods. Subject = (k + period + day) % 7,
        // teacher = that subject's teacher for the section's half of the school, so two
        // sections never share a teacher at the same time.
        $slots = 0;
        foreach ($sections as $sec) {
            foreach (self::DAYS as $d => $day) {
                foreach (self::PERIODS as $p => [$from, $to]) {
                    $si = ($sec['k'] + $p + $d) % count(self::SUBJECTS);
                    $ctx->insert('timetable_slots', [
                        'academic_year_id' => $yearId,
                        'class_id' => $classes[$sec['class']]['id'],
                        'section_id' => $sec['id'],
                        'subject_id' => $subjects[$si],
                        'teacher_id' => $teacherOf[$si][$sec['level'] <= 2 ? 0 : 1],
                        'day_of_week' => $day,
                        'start_time' => $from.':00',
                        'end_time' => $to.':00',
                        'room' => $si === 6 ? ($this->es ? 'Patio' : 'Playground') : $sec['room'],
                    ]);
                    $slots++;
                }
            }
        }

        // ---- Daily register: every school day from the start of the year (max ~8 weeks back) to yesterday.
        // Today is left empty so "Take register" can be tried.
        $from = $yearStart->copy()->max(now()->subWeeks(8)->startOfDay());
        $marks = 0;
        for ($day = $from->copy(); $day->lt(now()->startOfDay()); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            foreach ($students as $s) {
                $r = random_int(1, 100);
                // A couple of students miss more often (shows up in the reports).
                if ($s['n'] % 17 === 3) {
                    $r -= 8;
                }
                $status = $r <= 4 ? 'absent' : ($r <= 6 ? 'excused' : ($r <= 11 ? 'late' : 'present'));
                $ctx->insert('student_attendances', [
                    'student_id' => $s['id'],
                    'enrollment_id' => $s['enrollment'],
                    'class_id' => $s['class_id'],
                    'section_id' => $s['section_id'],
                    'subject_id' => null,
                    'attendance_date' => $day->toDateString(),
                    'status' => $status,
                    'remarks' => $status === 'late' ? $this->t('late_note') : ($status === 'excused' ? $this->t('excused_note') : null),
                    'marked_by' => $ctx->userId,
                ]);
                $marks++;
            }
        }

        // ---- Exams: Term 1 published with marks (every subject), Term 2 scheduled.
        $term1Start = now()->subDays(14)->startOfDay();
        if ($term1Start->lt($yearStart->copy()->addDays(7))) {
            $term1Start = $yearStart->copy()->addWeeks(9);
        }
        while ($term1Start->isWeekend()) {
            $term1Start->addDay();
        }
        $term1Past = $term1Start->lt(now()->subDays(7));
        $exam1 = $ctx->insert('exams', [
            'academic_year_id' => $yearId, 'name' => $this->t('term1'), 'term' => 'term_1',
            'start_date' => $term1Start->toDateString(), 'end_date' => $this->schoolDay($term1Start, count(self::SUBJECTS) - 1)->toDateString(),
            'status' => $term1Past ? 'published' : 'scheduled', 'notes' => $demo,
        ]);
        $term2Start = $term1Start->copy()->addWeeks(10);
        while ($term2Start->isWeekend()) {
            $term2Start->addDay();
        }
        $exam2 = $ctx->insert('exams', [
            'academic_year_id' => $yearId, 'name' => $this->t('term2'), 'term' => 'term_2',
            'start_date' => $term2Start->toDateString(), 'end_date' => $this->schoolDay($term2Start, count(self::SUBJECTS) - 1)->toDateString(),
            'status' => $term2Start->lt(now()) ? 'completed' : 'scheduled', 'notes' => $demo,
        ]);

        // Marks out of 20 in Spanish schools (pass 11), out of 100 otherwise (pass 40).
        [$max, $pass] = $this->es ? [20, 11] : [100, 40];
        $examSubjects = $results = 0;
        foreach ([$exam1 => $term1Start, $exam2 => $term2Start] as $examId => $examStart) {
            foreach ($classes as $ci => $class) {
                foreach ($subjects as $si => $subjectId) {
                    $esId = $ctx->insert('exam_subjects', [
                        'exam_id' => $examId,
                        'class_id' => $class['id'],
                        'subject_id' => $subjectId,
                        'exam_date' => $this->schoolDay($examStart, $si)->toDateString(),
                        'start_time' => '09:00:00',
                        'duration_minutes' => 90,
                        'max_marks' => $max,
                        'pass_marks' => $pass,
                        'room' => $this->t('hall', ['n' => $ci + 1]),
                    ]);
                    $examSubjects++;
                    if ($examId !== $exam1 || ! $term1Past) {
                        continue;
                    }
                    foreach ($students as $s) {
                        if ($s['class'] !== $ci) {
                            continue;
                        }
                        $absent = random_int(1, 60) === 1;
                        // Each student has a level; a few fail a subject.
                        $base = 0.55 + (($s['n'] * 37) % 40) / 100;
                        $score = $absent ? null : max(0, min($max, (int) round($max * ($base + random_int(-12, 12) / 100))));
                        $ctx->insert('exam_results', [
                            'exam_subject_id' => $esId,
                            'student_id' => $s['id'],
                            'marks' => $score,
                            'is_absent' => $absent ? 1 : 0,
                            'grade' => $absent ? null : ExamResult::gradeFor($score * (100 / $max)),
                            'remarks' => $absent ? $this->t('absent_note') : null,
                            'entered_by' => $ctx->userId,
                        ]);
                        $results++;
                    }
                }
            }
        }

        // ---- Fees
        $fees = [];
        foreach (self::FEES as [$es, $en, $freq, $amount, $optional]) {
            $fees[] = [
                'id' => $ctx->insert('fee_structures', [
                    'academic_year_id' => $yearId, 'class_id' => null, 'name' => $this->es ? $es : $en,
                    'frequency' => $freq, 'amount' => $amount,
                    'due_date' => $yearStart->copy()->addDays(30)->toDateString(),
                    'is_optional' => $optional ? 1 : 0, 'is_active' => 1, 'description' => $demo,
                ]),
                'name' => $this->es ? $es : $en, 'amount' => $amount, 'optional' => $optional,
            ];
        }

        $invoices = $items = $payments = 0;
        $invoiceDate = $yearStart->copy()->addDays(3);
        foreach ($students as $s) {
            $n = $s['n'];
            $lines = array_values(array_filter($fees, fn ($f) => ! $f['optional'] || $n % 3 === 0));
            $total = array_sum(array_column($lines, 'amount'));
            $roll = $n % 6; // 0-2 paid, 3 partial, 4 unpaid (overdue), 5 unpaid (due later)
            $paid = $roll <= 2 ? $total : ($roll === 3 ? round($total / 2, 2) : 0);
            $due = $roll === 5 ? now()->addDays(15) : $invoiceDate->copy()->addDays(30);
            $invoiceId = $ctx->insert('school_invoices', [
                'reference' => SchoolInvoice::nextReference('FEE'),
                'student_id' => $s['id'],
                'academic_year_id' => $yearId,
                'class_id' => $s['class_id'],
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $due->toDateString(),
                'subtotal' => $total, 'discount' => 0, 'total' => $total, 'paid' => $paid,
                'status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'notes' => $demo,
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
                // Paid ones: one payment early, some this month.
                $paidOn = $roll === 0 ? now()->startOfMonth()->addDays(random_int(0, max(0, now()->day - 1))) : $invoiceDate->copy()->addDays(random_int(1, 20));
                $ctx->insert('school_payments', [
                    'reference' => SchoolPayment::nextReference('SPY'),
                    'invoice_id' => $invoiceId,
                    'student_id' => $s['id'],
                    'paid_on' => $paidOn->toDateString(),
                    'amount' => $paid,
                    'method' => $ctx->pick(['cash', 'bank_transfer', 'card']),
                    'notes' => $demo,
                    'created_by' => $ctx->userId,
                ]);
                $payments++;
            }
        }

        return [
            'Academic years' => 1,
            'Classes' => count($classes),
            'Sections' => count($sections),
            'Subjects' => count($subjects),
            'Teachers' => $teacherCount,
            'Students' => count($students),
            'Enrollments' => count($students),
            'Timetable periods' => $slots,
            'Attendance marks' => $marks,
            'Exams' => 2,
            'Exam subjects' => $examSubjects,
            'Exam results' => $results,
            'Fee structures' => count($fees),
            'Fee invoices' => $invoices,
            'Invoice items' => $items,
            'Payments' => $payments,
        ];
    }

    /** $start + $n school days (weekends skipped). */
    private function schoolDay(Carbon $start, int $n): Carbon
    {
        $d = $start->copy();
        while ($n > 0) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $n--;
            }
        }

        return $d;
    }
}
