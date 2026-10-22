<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Hospital: staff shifts, the admission desk, treatments and the operating
 * room (user's request, 2026-10-21).
 *  - hms_staff_shifts: who works each shift (07-15 / 15-23 / 23-07, and the
 *    24 h emergency shift 07-07): ER doctor, specialists, nurse supervisor,
 *    nurses; hms_shift_patients: the patients each one sees in that shift.
 *  - er_cases.location: waiting_room / er_bed (the admission desk's triage
 *    sends a patient to the waiting room or a bed).
 *  - hms_visitors: visitors registered at the admission desk.
 *  - hms_treatments (+ doctors), hms_medication_orders, hms_medication_doses
 *    (the administration record), hms_dispenses (pharmacy dispatch).
 *  - hms_operating_rooms, hms_surgeries, hms_surgery_events (patient moves).
 *  - permissions, sidebar entries, Spanish menu texts. Safe to run twice.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'hms_admission_desk' => 'Admission desk (intake, triage, waiting room)',
        'hms_visitors' => 'Visitors',
        'hms_shifts' => 'Staff shifts (assign)',
        'hms_my_patients' => 'My patients (shift)',
        'hms_treatments' => 'Treatments',
        'hms_treatments_administer' => 'Treatments: give medication',
        'hms_surgery' => 'Operating room',
        'hms_pharmacy_dispense' => 'Pharmacy: dispense',
    ];

    /** [menu key, after key] */
    private const MENU = [
        ['/app/hospital/admission-desk', '/app/hospital/dashboard'],
        ['/app/hospital/my-patients', '/app/hospital/admission-desk'],
        ['/app/hospital/treatments', '/app/hospital/admissions'],
        ['/app/hospital/surgery', '/app/hospital/treatments'],
        ['/app/hospital/shifts', '/app/hospital/doctors'],
    ];

    private const TEXTS = [
        'Admission desk' => 'Admisión',
        'My patients' => 'Mis pacientes',
        'Treatments' => 'Tratamientos',
        'Operating room' => 'Quirófano',
        'Staff shifts' => 'Turnos del personal',
        'Inpatients' => 'Internaciones',
    ];

    public function up(): void
    {
        $common = function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('company_id')->nullable()->index();
        };

        if (! Schema::hasTable('hms_staff_shifts')) {
            Schema::create('hms_staff_shifts', function (Blueprint $t) use ($common) {
                $common($t);
                $t->date('shift_date')->index();
                $t->string('shift', 16);          // morning, afternoon, night, er24
                $t->string('position', 24);       // er_doctor, specialist, nurse_supervisor, nurse
                $t->unsignedBigInteger('doctor_id')->nullable();
                $t->unsignedInteger('employee_id')->nullable();
                $t->unsignedBigInteger('ward_id')->nullable();
                $t->string('notes', 255)->nullable();
                $t->integer('created_by')->nullable();
                $t->timestamps(6);
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_shift_patients')) {
            Schema::create('hms_shift_patients', function (Blueprint $t) use ($common) {
                $common($t);
                $t->unsignedBigInteger('staff_shift_id')->index();
                $t->unsignedBigInteger('patient_id')->index();
                $t->dateTime('visited_at')->nullable();
                $t->string('notes', 255)->nullable();
                $t->timestamps(6);
            });
        }
        if (Schema::hasTable('er_cases') && ! Schema::hasColumn('er_cases', 'location')) {
            Schema::table('er_cases', fn (Blueprint $t) => $t->string('location', 16)->nullable()->after('bed_id'));
            DB::table('er_cases')->whereNull('location')->update(['location' => DB::raw("CASE WHEN bed_id IS NULL THEN 'waiting_room' ELSE 'er_bed' END")]);
        }
        if (! Schema::hasTable('hms_visitors')) {
            Schema::create('hms_visitors', function (Blueprint $t) use ($common) {
                $common($t);
                $t->unsignedBigInteger('patient_id')->index();
                $t->string('name', 191);
                $t->string('document', 64)->nullable();
                $t->string('relation', 64)->nullable();
                $t->string('phone', 40)->nullable();
                $t->dateTime('checked_in_at');
                $t->dateTime('checked_out_at')->nullable();
                $t->string('badge', 32)->nullable();
                $t->string('notes', 255)->nullable();
                $t->integer('created_by')->nullable();
                $t->timestamps(6);
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_treatments')) {
            Schema::create('hms_treatments', function (Blueprint $t) use ($common) {
                $common($t);
                $t->string('reference', 32);
                $t->unsignedBigInteger('patient_id')->index();
                $t->unsignedBigInteger('admission_id')->nullable();
                $t->unsignedBigInteger('er_case_id')->nullable();
                $t->unsignedBigInteger('surgery_id')->nullable();
                $t->unsignedBigInteger('lead_doctor_id')->nullable();
                $t->text('diagnosis')->nullable();
                $t->text('plan')->nullable();
                $t->string('status', 16)->default('active')->index(); // active, completed, cancelled
                $t->dateTime('started_at');
                $t->dateTime('ended_at')->nullable();
                $t->integer('created_by')->nullable();
                $t->timestamps(6);
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_treatment_doctors')) {
            Schema::create('hms_treatment_doctors', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('treatment_id')->index();
                $t->unsignedBigInteger('doctor_id');
                $t->string('role', 32)->nullable(); // lead, consultant, surgeon...
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('hms_medication_orders')) {
            Schema::create('hms_medication_orders', function (Blueprint $t) use ($common) {
                $common($t);
                $t->unsignedBigInteger('treatment_id')->index();
                $t->unsignedInteger('product_id')->nullable();
                $t->string('medicine', 191);
                $t->string('dose', 64);
                $t->string('route', 24)->nullable();          // oral, iv, im, sc, topical, inhaled...
                $t->unsignedSmallInteger('every_hours');       // 4, 6, 8, 12, 24
                $t->dateTime('starts_at');
                $t->unsignedSmallInteger('days')->default(1);
                $t->decimal('units_per_dose', 10, 2)->default(1); // stock units dispensed per dose
                $t->string('instructions', 255)->nullable();
                $t->string('status', 16)->default('active');   // active, stopped, completed
                $t->unsignedBigInteger('doctor_id')->nullable();
                $t->integer('created_by')->nullable();
                $t->timestamps(6);
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_medication_doses')) {
            Schema::create('hms_medication_doses', function (Blueprint $t) use ($common) {
                $common($t);
                $t->unsignedBigInteger('order_id')->index();
                $t->unsignedBigInteger('treatment_id')->index();
                $t->unsignedBigInteger('patient_id')->index();
                $t->dateTime('scheduled_at')->index();
                $t->string('status', 16)->default('pending');  // pending, given, omitted, refused
                $t->dateTime('given_at')->nullable();
                $t->integer('given_by')->nullable();
                $t->boolean('dispensed')->default(false);
                $t->string('notes', 255)->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('hms_dispenses')) {
            Schema::create('hms_dispenses', function (Blueprint $t) use ($common) {
                $common($t);
                $t->unsignedBigInteger('order_id')->index();
                $t->unsignedInteger('product_id')->nullable();
                $t->unsignedInteger('warehouse_id')->nullable();
                $t->decimal('quantity', 10, 2);
                $t->unsignedSmallInteger('doses')->default(0);
                $t->dateTime('dispensed_at');
                $t->integer('dispensed_by')->nullable();
                $t->string('notes', 255)->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('hms_operating_rooms')) {
            Schema::create('hms_operating_rooms', function (Blueprint $t) use ($common) {
                $common($t);
                $t->string('name', 100);
                $t->string('status', 16)->default('available'); // available, in_use, cleaning, maintenance
                $t->string('notes', 255)->nullable();
                $t->timestamps(6);
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_surgeries')) {
            Schema::create('hms_surgeries', function (Blueprint $t) use ($common) {
                $common($t);
                $t->string('reference', 32);
                $t->unsignedBigInteger('patient_id')->index();
                $t->unsignedBigInteger('admission_id')->nullable();
                $t->unsignedBigInteger('operating_room_id')->nullable();
                $t->unsignedBigInteger('surgeon_id')->nullable();
                $t->unsignedBigInteger('anesthetist_id')->nullable();
                $t->text('team')->nullable();
                $t->dateTime('scheduled_at');
                $t->unsignedSmallInteger('estimated_minutes')->default(60);
                $t->string('status', 16)->default('scheduled')->index(); // scheduled, pre_op, in_surgery, recovery, completed, cancelled
                $t->string('priority', 16)->default('elective');          // elective, urgent, emergency
                $t->text('pre_op_diagnosis')->nullable();
                $t->text('planned_procedure')->nullable();
                $t->text('risks')->nullable();
                $t->boolean('consent_signed')->default(false);
                $t->text('procedure_performed')->nullable();
                $t->string('anesthesia', 32)->nullable();
                $t->dateTime('started_at')->nullable();
                $t->dateTime('ended_at')->nullable();
                $t->text('findings')->nullable();
                $t->text('complications')->nullable();
                $t->text('post_op_diagnosis')->nullable();
                $t->text('post_op_instructions')->nullable();
                $t->decimal('fee', 12, 2)->default(0);
                $t->integer('created_by')->nullable();
                $t->timestamps(6);
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_surgery_events')) {
            Schema::create('hms_surgery_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('surgery_id')->index();
                $t->string('event', 24);
                $t->dateTime('at');
                $t->integer('user_id')->nullable();
                $t->string('notes', 255)->nullable();
                $t->timestamps(6);
            });
        }

        $this->permissions();
        $this->menu();
        $this->translations();
    }

    private function permissions(): void
    {
        $base = DB::table('permissions')->where('name', 'hms_dashboard')->value('id');
        foreach (self::PERMISSIONS as $name => $label) {
            $pid = DB::table('permissions')->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            // Every role that already runs the hospital (has its dashboard) gets them; the hospital roles narrow it (HospitalRoles).
            foreach ($base ? DB::table('permission_role')->where('permission_id', $base)->pluck('role_id') : [] as $rid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
    }

    private function menu(): void
    {
        if (! Schema::hasTable('menu_positions')) {
            return;
        }
        foreach (self::MENU as [$key, $after]) {
            foreach (DB::table('menu_positions')->where('item_key', $after)->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
                if (DB::table('menu_positions')->where('company_id', $ref->company_id)->where('item_key', $key)->exists()) {
                    continue;
                }
                $same = fn ($q) => $ref->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $ref->parent_key);
                DB::table('menu_positions')->where('company_id', $ref->company_id)->where($same)->where('sort_order', '>', $ref->sort_order)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $ref->company_id, 'item_key' => $key, 'parent_key' => $ref->parent_key, 'sort_order' => $ref->sort_order + 1,
                    'external' => 0, 'is_custom' => 0, 'hidden' => (int) $ref->hidden, 'location' => $ref->location ?: 'sidebar',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function translations(): void
    {
        if (! Schema::hasTable('translations') || ! Schema::hasColumn('translations', 'es')) {
            return;
        }
        foreach (self::TEXTS as $key => $es) {
            TranslationKeys::ensure($key, ['es' => $es]);
            $row = DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->first();
            if ($row && trim((string) $row->es) === trim((string) ($row->en ?: $key))) {
                DB::table('translations')->where('id', $row->id)->update(['es' => $es, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        foreach (['hms_surgery_events', 'hms_surgeries', 'hms_operating_rooms', 'hms_dispenses', 'hms_medication_doses', 'hms_medication_orders',
            'hms_treatment_doctors', 'hms_treatments', 'hms_visitors', 'hms_shift_patients', 'hms_staff_shifts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
