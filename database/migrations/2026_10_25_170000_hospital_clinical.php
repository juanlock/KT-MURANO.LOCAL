<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital phase 3 (user's analysis, 2026-10-25): the clinical stay.
 *  - hms_progress_notes: the daily progress of the patient (SOAP).
 *  - hms_vitals: vital-sign rounds of the nurses.
 *  - hms_care_orders + hms_care_logs: diet, care and activity orders and when
 *    each was done (the nursing kardex).
 *  - hms_consults: interconsultations to another specialist (alerts).
 *  - hms_discharge_plans: the checklist 24-48 h before the discharge.
 *  - admissions.left_at: the patient physically left the room (medical
 *    discharge first, the bed freed when the room is really empty).
 *  - texts of the hospital alerts (notification bell).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
        };
        if (! Schema::hasTable('hms_progress_notes')) {
            Schema::create('hms_progress_notes', function (Blueprint $t) use ($base) {
                $base($t);
                $t->unsignedBigInteger('admission_id')->index();
                $t->unsignedBigInteger('patient_id')->index();
                $t->unsignedBigInteger('doctor_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->dateTime('noted_at');
                $t->text('subjective')->nullable();
                $t->text('objective')->nullable();
                $t->text('assessment')->nullable();
                $t->text('plan')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_vitals')) {
            Schema::create('hms_vitals', function (Blueprint $t) use ($base) {
                $base($t);
                $t->unsignedBigInteger('admission_id')->nullable()->index();
                $t->unsignedBigInteger('patient_id')->index();
                $t->dateTime('taken_at');
                $t->decimal('temperature', 4, 1)->nullable();
                $t->unsignedSmallInteger('pulse')->nullable();
                $t->unsignedSmallInteger('bp_systolic')->nullable();
                $t->unsignedSmallInteger('bp_diastolic')->nullable();
                $t->unsignedSmallInteger('respiratory_rate')->nullable();
                $t->unsignedTinyInteger('spo2')->nullable();
                $t->unsignedTinyInteger('pain')->nullable();
                $t->string('notes', 255)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hms_care_orders')) {
            Schema::create('hms_care_orders', function (Blueprint $t) use ($base) {
                $base($t);
                $t->unsignedBigInteger('admission_id')->index();
                $t->unsignedBigInteger('patient_id')->index();
                $t->string('kind', 16);                       // diet, care, vitals, activity, other
                $t->string('description', 255);
                $t->unsignedSmallInteger('every_hours')->nullable();
                $t->dateTime('starts_at');
                $t->dateTime('ends_at')->nullable();
                $t->string('status', 12)->default('active');  // active, stopped
                $t->unsignedBigInteger('doctor_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hms_care_logs')) {
            Schema::create('hms_care_logs', function (Blueprint $t) use ($base) {
                $base($t);
                $t->unsignedBigInteger('care_order_id')->index();
                $t->dateTime('done_at');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('notes', 255)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hms_consults')) {
            Schema::create('hms_consults', function (Blueprint $t) use ($base) {
                $base($t);
                $t->unsignedBigInteger('admission_id')->nullable()->index();
                $t->unsignedBigInteger('patient_id')->index();
                $t->unsignedBigInteger('requested_by')->nullable();      // doctor
                $t->unsignedBigInteger('requested_user')->nullable();
                $t->unsignedBigInteger('to_doctor_id')->nullable()->index();
                $t->string('specialty', 80)->nullable();
                $t->text('reason');
                $t->string('priority', 8)->default('normal');           // normal, urgent
                $t->string('status', 12)->default('requested');         // requested, accepted, answered, cancelled
                $t->text('answer')->nullable();
                $t->dateTime('answered_at')->nullable();
                $t->unsignedBigInteger('answered_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hms_discharge_plans')) {
            Schema::create('hms_discharge_plans', function (Blueprint $t) use ($base) {
                $base($t);
                $t->unsignedBigInteger('admission_id')->unique();
                $t->dateTime('planned_at');
                $t->json('items')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('admissions', 'left_at')) {
            Schema::table('admissions', fn (Blueprint $t) => $t->dateTime('left_at')->nullable()->after('discharged_at'));
            // Stays already closed: the patient is out.
            DB::table('admissions')->where('status', '!=', 'admitted')->whereNull('left_at')->update(['left_at' => DB::raw('discharged_at')]);
        }

        // The hospital alerts in the notification bell (PendingWorkController).
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach ([
                'Hms_beds_cleaning_late' => ['Beds waiting for cleaning (+30 min)', 'Camas esperando limpieza (+30 min)'],
                'Hms_consults_pending' => ['Interconsultations for you', 'Interconsultas para usted'],
                'Hms_lab_critical' => ['Critical lab results', 'Resultados críticos de laboratorio'],
                'Hms_discharges_due' => ['Discharges to prepare (checklist)', 'Altas por preparar (checklist)'],
                'Hms_rooms_to_free' => ['Discharged patients still in the room', 'Pacientes de alta aún en la habitación'],
            ] as $key => [$en, $es]) {
                TranslationKeys::ensure($key, ['en' => $en, 'es' => $es]);
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->update(['en' => $en, 'es' => $es]);
            }
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }
    }

    public function down(): void
    {
        foreach (['hms_discharge_plans', 'hms_consults', 'hms_care_logs', 'hms_care_orders', 'hms_vitals', 'hms_progress_notes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
