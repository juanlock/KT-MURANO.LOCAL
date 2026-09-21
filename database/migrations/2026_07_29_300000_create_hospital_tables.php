<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hospital Management System.
     *
     * Structured as the four things a hospital actually tracks:
     *   who     — patients, doctors, departments
     *   when    — appointments
     *   care    — visits (OPD) + prescriptions, admissions (IPD) + wards/beds,
     *             lab orders + results
     *   money   — invoices, invoice items, payments
     *
     * Two deliberate joins into the existing ERP rather than duplicating it:
     *   - prescription/invoice lines may point at a `products` row, so pharmacy
     *     items are the same catalogue the rest of the app sells;
     *   - a doctor may point at an `employees` row, so clinical staff are the
     *     same people HR already manages.
     * Both are NULLABLE — the module works standalone if neither is used.
     *
     * No DB-level foreign keys, matching the promotions/documents/fleet
     * convention here; integrity is enforced at the app layer.
     */
    public function up(): void
    {
        Schema::create('hospital_departments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('code', 32)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('location', 191)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('code', 32)->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            // Optional link to the HR record, so a doctor is not a second person.
            $table->integer('employee_id')->nullable()->index();
            $table->string('specialty', 191)->nullable();
            $table->string('qualification', 191)->nullable();
            $table->string('license_no', 64)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 191)->nullable();
            $table->decimal('consultation_fee', 12, 2)->default(0);
            // Weekly availability as JSON: {"mon":["09:00","17:00"], ...}
            $table->text('availability')->nullable();
            $table->string('image', 191)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            // Medical record number — the hospital-wide patient identifier.
            $table->string('mrn', 32)->unique();
            $table->string('name', 191)->index();
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 40)->nullable()->index();
            $table->string('email', 191)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('national_id', 64)->nullable()->index();

            $table->enum('blood_group', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])->nullable();
            $table->text('allergies')->nullable();
            $table->text('chronic_conditions')->nullable();

            $table->string('emergency_contact_name', 191)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->string('emergency_contact_relation', 64)->nullable();

            $table->string('insurance_provider', 191)->nullable();
            $table->string('insurance_number', 64)->nullable();
            $table->date('insurance_expiry')->nullable();

            // Optional link to the CRM customer, so a patient who also buys from
            // the shop is not a separate account.
            $table->integer('client_id')->nullable()->index();

            $table->string('image', 191)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive', 'deceased'])->default('active')->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('doctor_id')->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();

            $table->dateTime('scheduled_at')->index();
            $table->unsignedSmallInteger('duration_minutes')->default(15);
            $table->enum('type', ['consultation', 'follow_up', 'procedure', 'emergency', 'teleconsult'])
                ->default('consultation')->index();
            $table->enum('status', ['scheduled', 'confirmed', 'arrived', 'completed', 'cancelled', 'no_show'])
                ->default('scheduled')->index();

            $table->text('reason')->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        // OPD encounter — what actually happened at the appointment.
        Schema::create('patient_visits', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('doctor_id')->nullable()->index();
            $table->unsignedBigInteger('appointment_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();

            $table->dateTime('visit_date')->index();
            $table->enum('type', ['opd', 'emergency', 'follow_up', 'teleconsult'])->default('opd')->index();

            // Vitals, all optional — a triage nurse fills what they measured.
            $table->decimal('temperature', 5, 2)->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedSmallInteger('spo2')->nullable();
            $table->decimal('weight', 6, 2)->nullable();
            $table->decimal('height', 6, 2)->nullable();

            $table->text('complaint')->nullable();
            $table->text('examination')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment_plan')->nullable();
            $table->date('follow_up_date')->nullable()->index();

            $table->enum('status', ['open', 'completed'])->default('completed')->index();
            $table->decimal('fee', 12, 2)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('visit_id')->nullable()->index();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('doctor_id')->nullable()->index();
            $table->date('prescribed_on')->index();
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('prescription_id')->index();
            // Points at the pharmacy catalogue when the drug is stocked; the
            // free-text name is always kept so an off-formulary drug still works.
            $table->integer('product_id')->nullable()->index();
            $table->string('medicine', 191);
            $table->string('dosage', 64)->nullable();
            $table->string('frequency', 64)->nullable();
            $table->string('duration', 64)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('instructions', 255)->nullable();
            $table->timestamps(6);
        });

        Schema::create('hospital_wards', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->enum('type', ['general', 'private', 'semi_private', 'icu', 'nicu', 'maternity', 'isolation'])
                ->default('general')->index();
            $table->string('floor', 40)->nullable();
            $table->decimal('daily_rate', 12, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('hospital_beds', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ward_id')->index();
            $table->string('bed_number', 40);
            // Kept in sync by the admission controller; 'occupied' always means
            // there is an open admission holding it.
            $table->enum('status', ['available', 'occupied', 'maintenance', 'reserved'])
                ->default('available')->index();
            $table->decimal('daily_rate', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->unique(['ward_id', 'bed_number']);
        });

        Schema::create('admissions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('doctor_id')->nullable()->index();
            $table->unsignedBigInteger('ward_id')->nullable()->index();
            $table->unsignedBigInteger('bed_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();

            $table->dateTime('admitted_at')->index();
            $table->dateTime('discharged_at')->nullable()->index();
            $table->decimal('daily_rate', 12, 2)->default(0);

            $table->text('reason')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('discharge_summary')->nullable();
            $table->enum('status', ['admitted', 'discharged', 'transferred', 'deceased'])
                ->default('admitted')->index();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('lab_tests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('code', 32)->nullable()->index();
            $table->string('category', 100)->nullable()->index();
            $table->string('sample_type', 100)->nullable();
            $table->string('unit', 40)->nullable();
            // Free text: reference ranges differ by sex/age and are printed as-is.
            $table->string('normal_range', 100)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedSmallInteger('turnaround_hours')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('lab_orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('doctor_id')->nullable()->index();
            $table->unsignedBigInteger('visit_id')->nullable()->index();
            $table->dateTime('ordered_at')->index();
            $table->dateTime('completed_at')->nullable();
            $table->enum('priority', ['routine', 'urgent', 'stat'])->default('routine')->index();
            $table->enum('status', ['ordered', 'sample_collected', 'in_progress', 'completed', 'cancelled'])
                ->default('ordered')->index();
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('lab_order_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lab_order_id')->index();
            $table->unsignedBigInteger('lab_test_id')->index();
            $table->string('test_name', 191);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('result_value', 191)->nullable();
            $table->string('unit', 40)->nullable();
            $table->string('normal_range', 100)->nullable();
            // Set by the tech reading the result, not derived — ranges are text.
            $table->enum('flag', ['normal', 'low', 'high', 'critical'])->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps(6);
        });

        Schema::create('hospital_invoices', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('patient_id')->index();
            // Whichever episode of care produced the bill.
            $table->unsignedBigInteger('visit_id')->nullable()->index();
            $table->unsignedBigInteger('admission_id')->nullable()->index();
            $table->unsignedBigInteger('lab_order_id')->nullable()->index();

            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid', 14, 2)->default(0);
            $table->enum('status', ['draft', 'unpaid', 'partial', 'paid', 'cancelled'])
                ->default('unpaid')->index();
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('hospital_invoice_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->index();
            $table->enum('type', ['consultation', 'procedure', 'medicine', 'lab', 'bed', 'other'])
                ->default('other')->index();
            // Set for medicine lines drawn from the pharmacy catalogue.
            $table->integer('product_id')->nullable()->index();
            $table->string('description', 191);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps(6);
        });

        Schema::create('hospital_payments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('reference', 32)->index();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('patient_id')->index();
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
        Schema::dropIfExists('hospital_payments');
        Schema::dropIfExists('hospital_invoice_items');
        Schema::dropIfExists('hospital_invoices');
        Schema::dropIfExists('lab_order_items');
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('lab_tests');
        Schema::dropIfExists('admissions');
        Schema::dropIfExists('hospital_beds');
        Schema::dropIfExists('hospital_wards');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('patient_visits');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('hospital_departments');
    }
};
