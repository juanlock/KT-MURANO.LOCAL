<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamResult extends Model
{
    // Multicompany (2026-10-20): only the acting company's rows.
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'exam_results';

    protected $fillable = [
        'exam_subject_id', 'student_id', 'marks', 'is_absent', 'grade', 'remarks', 'entered_by',
    ];

    protected $casts = [
        'exam_subject_id' => 'integer',
        'student_id' => 'integer',
        'marks' => 'decimal:2',
        'is_absent' => 'boolean',
        'entered_by' => 'integer',
    ];

    public function examSubject()
    {
        return $this->belongsTo(ExamSubject::class, 'exam_subject_id', 'id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }

    /**
     * Default grading scale, applied when marks are saved.
     *
     * A school with its own bands would replace this one method; keeping the
     * scale in ONE place is why the grade is derived on save rather than typed
     * per row, where two people would inevitably disagree about a 79.5.
     */
    public static function gradeFor($percentage, $maxMarks = null)
    {
        if ($percentage === null) {
            return null;
        }

        // Marks out of 20 (vigesimal, Peru; user's rule 2026-10-21): one letter per
        // mark -- 20 A+, 19 A, 18 B+, 17 B, 16 C+, 15 C, 14 D+, 13 D, 12 E+, 11 E
        // (pass), 10 and below F.
        if ((float) $maxMarks === 20.0) {
            return self::vigesimalGrade((int) round($percentage * 20 / 100));
        }

        $scale = [
            [90, 'A+'], [80, 'A'], [70, 'B+'], [60, 'B'],
            [50, 'C'], [40, 'D'], [0, 'F'],
        ];

        foreach ($scale as [$floor, $grade]) {
            if ($percentage >= $floor) {
                return $grade;
            }
        }

        return 'F';
    }

    /** The letter for a mark out of 20. */
    public static function vigesimalGrade(int $mark): string
    {
        return [20 => 'A+', 19 => 'A', 18 => 'B+', 17 => 'B', 16 => 'C+', 15 => 'C',
            14 => 'D+', 13 => 'D', 12 => 'E+', 11 => 'E'][$mark] ?? 'F';
    }

    /** Grade points for the same scale, used to average a term. */
    public static function pointsFor($grade)
    {
        return [
            'A+' => 5.0, 'A' => 4.5, 'B+' => 4.0, 'B' => 3.5,
            'C' => 3.0, 'D' => 2.0, 'F' => 0.0,
        ][$grade] ?? 0.0;
    }
}
