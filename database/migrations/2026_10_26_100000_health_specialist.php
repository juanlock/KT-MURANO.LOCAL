<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Health Specialist (specialist clinic) on top of Hospital Management
 * (user's request, 2026-10-26): the outpatient flow
 *   book -> confirm -> pre-register -> check-in (pay / insurance) -> vital
 *   signs -> "ready" -> consultation (EMR by specialty) -> orders ->
 *   follow-up -> satisfaction survey (NPS).
 *  - appointments: the flow status and its times, first / follow-up visit,
 *    channel, payment, the portal pre-registration, confirmation, survey;
 *  - patient_visits: the specialty template and its data, follow-up note;
 *  - doctors: the specialty template they use;
 *  - hms_patient_problems (problem-oriented record), hms_surveys (NPS),
 *    hms_appointment_requests (follow-ups / referrals / portal requests to schedule);
 *  - permissions, sidebar entries, Spanish menu texts. Safe to run twice.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'hms_reception' => 'Reception and schedule',
        'hms_nursing_station' => 'Nursing station (vital signs)',
        'hms_specialist' => 'Specialist consultation (EMR)',
        'hms_clinic_reports' => 'Clinic reports',
    ];

    /** [menu key, after key] */
    private const MENU = [
        ['/app/hospital/agenda', '/app/hospital/dashboard'],
        ['/app/hospital/nursing', '/app/hospital/agenda'],
        ['/app/hospital/specialist', '/app/hospital/nursing'],
        ['/app/hospital/clinic-reports', '/app/hospital/reports'],
    ];

    private const TEXTS = [
        'Reception and schedule' => 'Recepción y agenda',
        'Nursing station' => 'Estación de enfermería',
        'My consultation' => 'Mi consulta',
        'Clinic reports' => 'Reportes del consultorio',
    ];

    public function up(): void
    {
        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $t) {
                $add = fn ($c, $fn) => Schema::hasColumn('appointments', $c) ? null : $fn();
                $add('flow_status', fn () => $t->string('flow_status', 16)->nullable()->after('status'));
                $add('checked_in_at', fn () => $t->dateTime('checked_in_at')->nullable());
                $add('vitals_at', fn () => $t->dateTime('vitals_at')->nullable());
                $add('ready_at', fn () => $t->dateTime('ready_at')->nullable());
                $add('consult_started_at', fn () => $t->dateTime('consult_started_at')->nullable());
                $add('consult_ended_at', fn () => $t->dateTime('consult_ended_at')->nullable());
                $add('is_first_visit', fn () => $t->boolean('is_first_visit')->nullable());
                $add('channel', fn () => $t->string('channel', 16)->nullable());
                $add('payment_status', fn () => $t->string('payment_status', 16)->nullable());
                $add('invoice_id', fn () => $t->unsignedBigInteger('invoice_id')->nullable());
                $add('pre_registration', fn () => $t->text('pre_registration')->nullable());
                $add('pre_registered_at', fn () => $t->dateTime('pre_registered_at')->nullable());
                $add('confirmed_at', fn () => $t->dateTime('confirmed_at')->nullable());
                $add('request_id', fn () => $t->unsignedBigInteger('request_id')->nullable());
            });
        }
        if (Schema::hasTable('patient_visits')) {
            Schema::table('patient_visits', function (Blueprint $t) {
                if (! Schema::hasColumn('patient_visits', 'specialty_template')) {
                    $t->string('specialty_template', 24)->nullable();
                }
                if (! Schema::hasColumn('patient_visits', 'specialty_data')) {
                    $t->text('specialty_data')->nullable();
                }
                if (! Schema::hasColumn('patient_visits', 'follow_up_note')) {
                    $t->string('follow_up_note', 255)->nullable();
                }
            });
        }
        if (Schema::hasTable('doctors') && ! Schema::hasColumn('doctors', 'specialty_template')) {
            Schema::table('doctors', fn (Blueprint $t) => $t->string('specialty_template', 24)->nullable());
        }
        if (! Schema::hasTable('hms_patient_problems')) {
            Schema::create('hms_patient_problems', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('patient_id')->index();
                $t->string('problem', 191);
                $t->string('code', 24)->nullable();
                $t->string('status', 12)->default('active'); // active, resolved
                $t->date('onset_on')->nullable();
                $t->string('notes', 255)->nullable();
                $t->unsignedBigInteger('doctor_id')->nullable();
                $t->timestamps(6);
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_surveys')) {
            Schema::create('hms_surveys', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('appointment_id')->index();
                $t->unsignedBigInteger('patient_id');
                $t->unsignedBigInteger('doctor_id')->nullable();
                $t->string('token', 64)->unique();
                $t->dateTime('sent_at')->nullable();
                $t->dateTime('answered_at')->nullable();
                $t->unsignedTinyInteger('score')->nullable(); // 0-10
                $t->text('comment')->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('hms_appointment_requests')) {
            Schema::create('hms_appointment_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('patient_id');
                $t->unsignedBigInteger('doctor_id')->nullable();
                $t->unsignedBigInteger('from_visit_id')->nullable();
                $t->unsignedBigInteger('from_doctor_id')->nullable();
                $t->string('kind', 16); // follow_up, referral, portal
                $t->date('wanted_on')->nullable();
                $t->string('reason', 255)->nullable();
                $t->string('studies', 255)->nullable();
                $t->string('status', 12)->default('pending'); // pending, scheduled, cancelled
                $t->unsignedBigInteger('appointment_id')->nullable();
                $t->integer('created_by')->nullable();
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
            // The company admins (roles with the hospital dashboard) get them; the clinic roles narrow it.
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
        Schema::dropIfExists('hms_appointment_requests');
        Schema::dropIfExists('hms_surveys');
        Schema::dropIfExists('hms_patient_problems');
    }
};
