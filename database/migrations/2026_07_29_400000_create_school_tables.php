<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * School Management System.
     *
     * Structured around the things a school actually tracks:
     *   structure — academic years, classes, sections, subjects
     *   people    — students (+ guardians), teachers, enrolments
     *   learning  — attendance, timetable, exams, results
     *   money     — fee structures, invoices, payments
     *
     * The pivotal idea is the ENROLMENT: a student is not "in class 5", they are
     * enrolled in a class+section FOR AN ACADEMIC YEAR. Attendance, results and
     * fees all hang off that, which is what makes promotion and year-on-year
     * history work instead of overwriting last year's record.
     *
     * A teacher may point at an `employees` row so school staff are the same
     * people HR already manages — nullable, so the module works standalone.
     *
     * No DB-level foreign keys, matching the convention in this codebase.
     */
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            // Exactly one year is current; the controller enforces it.
            $table->boolean('is_current')->default(false)->index();
            $table->boolean('is_locked')->default(false);
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('school_classes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 100);
            $table->string('code', 32)->nullable()->index();
            // Ordering for promotion: class 5 -> class 6 is level 5 -> 6.
            $table->unsignedSmallInteger('level')->default(0)->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('class_sections', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('class_id')->index();
            $table->string('name', 50);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('room', 50)->nullable();
            // Form teacher / class teacher.
            $table->unsignedBigInteger('teacher_id')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps(6);
            $table->softDeletes();

            $table->unique(['class_id', 'name']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 100);
            $table->string('code', 32)->nullable()->index();
            $table->unsignedBigInteger('class_id')->nullable()->index();
            $table->enum('type', ['core', 'elective', 'optional'])->default('core')->index();
            // Weight when averaging a term result.
            $table->decimal('credit', 5, 2)->default(1);
            $table->unsignedSmallInteger('pass_mark')->default(40);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('teachers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('employee_code', 32)->nullable()->index();
            $table->string('name', 191)->index();
            // Optional link to the HR record.
            $table->integer('employee_id')->nullable()->index();
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->string('phone', 40)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('qualification', 191)->nullable();
            $table->string('specialization', 191)->nullable();
            $table->date('joining_date')->nullable();
            $table->decimal('salary', 14, 2)->nullable();
            $table->text('address')->nullable();
            $table->string('image', 191)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            // School-wide identifier, issued once and kept for life.
            $table->string('admission_number', 32)->unique();
            $table->string('name', 191)->index();
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->date('date_of_birth')->nullable();
            $table->date('admission_date')->nullable()->index();
            $table->string('blood_group', 8)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 191)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('national_id', 64)->nullable();
            $table->text('medical_notes')->nullable();

            // Guardian — the person the school actually contacts.
            $table->string('guardian_name', 191)->nullable();
            $table->string('guardian_relation', 64)->nullable();
            $table->string('guardian_phone', 40)->nullable()->index();
            $table->string('guardian_email', 191)->nullable();
            $table->string('guardian_occupation', 100)->nullable();

            // Optional link to the CRM customer who pays the fees.
            $table->integer('client_id')->nullable()->index();

            $table->string('image', 191)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive', 'graduated', 'transferred', 'expelled'])
                ->default('active')->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('academic_year_id')->index();
            $table->unsignedBigInteger('class_id')->index();
            $table->unsignedBigInteger('section_id')->nullable()->index();
            // Register number within the section.
            $table->string('roll_number', 32)->nullable();
            $table->date('enrolled_on')->nullable();
            $table->enum('status', ['active', 'promoted', 'transferred', 'left', 'repeated'])
                ->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            // One enrolment per student per year — the rule the whole module rests on.
            $table->unique(['student_id', 'academic_year_id']);
        });

        Schema::create('student_attendances', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('enrollment_id')->nullable()->index();
            $table->unsignedBigInteger('class_id')->index();
            $table->unsignedBigInteger('section_id')->nullable()->index();
            // Null = whole-day register; set = per-period attendance.
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->date('attendance_date')->index();
            $table->enum('status', ['present', 'absent', 'late', 'excused', 'half_day'])
                ->default('present')->index();
            $table->string('remarks', 255)->nullable();
            $table->integer('marked_by')->nullable();
            $table->timestamps(6);

            // Re-marking a register updates rather than duplicating.
            $table->unique(['student_id', 'attendance_date', 'subject_id'], 'attendance_unique_slot');
        });

        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('academic_year_id')->index();
            $table->unsignedBigInteger('class_id')->index();
            $table->unsignedBigInteger('section_id')->nullable()->index();
            $table->unsignedBigInteger('subject_id')->index();
            $table->unsignedBigInteger('teacher_id')->nullable()->index();
            $table->enum('day_of_week', ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room', 50)->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('academic_year_id')->index();
            $table->string('name', 191);
            $table->enum('term', ['term_1', 'term_2', 'term_3', 'final', 'other'])->default('term_1')->index();
            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'ongoing', 'completed', 'published'])
                ->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        // One row per subject sat by one class in one exam.
        Schema::create('exam_subjects', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('exam_id')->index();
            $table->unsignedBigInteger('class_id')->index();
            $table->unsignedBigInteger('subject_id')->index();
            $table->date('exam_date')->nullable();
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('max_marks', 8, 2)->default(100);
            $table->decimal('pass_marks', 8, 2)->default(40);
            $table->string('room', 50)->nullable();
            $table->timestamps(6);

            $table->unique(['exam_id', 'class_id', 'subject_id'], 'exam_subject_unique');
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('exam_subject_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            // Null marks = absent; 0 means they sat it and scored nothing.
            $table->decimal('marks', 8, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->string('grade', 8)->nullable();
            $table->string('remarks', 255)->nullable();
            $table->integer('entered_by')->nullable();
            $table->timestamps(6);

            $table->unique(['exam_subject_id', 'student_id'], 'result_unique');
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('academic_year_id')->index();
            // Null class = applies to every class (e.g. a registration fee).
            $table->unsignedBigInteger('class_id')->nullable()->index();
            $table->string('name', 191);
            $table->enum('frequency', ['once', 'monthly', 'termly', 'yearly'])->default('termly')->index();
            $table->decimal('amount', 14, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->text('description')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('school_invoices', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('academic_year_id')->nullable()->index();
            $table->unsignedBigInteger('class_id')->nullable()->index();
            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid', 14, 2)->default(0);
            $table->enum('status', ['draft', 'unpaid', 'partial', 'paid', 'cancelled'])
                ->default('unpaid')->index();
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('school_invoice_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('fee_structure_id')->nullable()->index();
            $table->string('description', 191);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps(6);
        });

        Schema::create('school_payments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->date('paid_on')->index();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('method', 64)->default('cash');
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_payments');
        Schema::dropIfExists('school_invoice_items');
        Schema::dropIfExists('school_invoices');
        Schema::dropIfExists('fee_structures');
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exam_subjects');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('timetable_slots');
        Schema::dropIfExists('student_attendances');
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('students');
        Schema::dropIfExists('teachers');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('class_sections');
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('academic_years');
    }
};
