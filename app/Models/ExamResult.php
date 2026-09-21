<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamResult extends Model
{
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
    public static function gradeFor($percentage)
    {
        if ($percentage === null) {
            return null;
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

    /** Grade points for the same scale, used to average a term. */
    public static function pointsFor($grade)
    {
        return [
            'A+' => 5.0, 'A' => 4.5, 'B+' => 4.0, 'B' => 3.5,
            'C' => 3.0, 'D' => 2.0, 'F' => 0.0,
        ][$grade] ?? 0.0;
    }
}
