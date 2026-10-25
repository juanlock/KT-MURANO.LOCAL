<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admission window + triage (user's rules, 2026-10-25). An arrival (er_cases) is:
 *  - emergency: the window registers the patient, the money (insurance or a
 *    credit card) and why they come -> waiting for triage; the triage nurse
 *    calls them, takes vital signs, weight and height, describes the reason,
 *    sets the level and sends them to an ER bed;
 *  - critical: straight to an ER bed; registration and triage are completed
 *    after the patient is seen;
 *  - scheduled: referred (e.g. for surgery), already registered with their
 *    money -> waiting for a bed, no triage.
 * Without the financial data an emergency patient is never admitted to a ward
 * (waiting room / ER bed until sent home).
 *  - er_cases: pathway, triage / registration status, weight, height, triage
 *    notes, usual medication, payer (type, insurer, policy, authorization,
 *    card type + last 4 digits only), referral (who, procedure, planned date);
 *  - permission hms_triage, the Triaje role, the menu entry; Vitalis gets a
 *    triage nurse login (standard employee password).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('er_cases') && ! Schema::hasColumn('er_cases', 'pathway')) {
            Schema::table('er_cases', function (Blueprint $t) {
                $t->string('pathway', 12)->default('emergency')->after('arrival_mode');       // emergency, critical, scheduled
                $t->string('triage_status', 10)->default('pending')->after('triage_level');   // pending, called, done, skipped
                $t->dateTime('triage_called_at')->nullable()->after('triage_status');
                $t->dateTime('triaged_at')->nullable()->after('triage_called_at');
                $t->unsignedBigInteger('triaged_by')->nullable()->after('triaged_at');
                $t->decimal('weight_kg', 5, 1)->nullable()->after('pain_score');
                $t->decimal('height_cm', 5, 1)->nullable()->after('weight_kg');
                $t->text('triage_notes')->nullable()->after('height_cm');
                $t->string('usual_medication', 500)->nullable()->after('triage_notes');
                $t->string('registration_status', 10)->default('done')->after('usual_medication'); // pending, done
                $t->string('payer_type', 12)->nullable()->after('registration_status');           // self_pay, private, public
                $t->string('insurer', 120)->nullable()->after('payer_type');
                $t->string('policy_no', 60)->nullable()->after('insurer');
                $t->string('authorization_no', 60)->nullable()->after('policy_no');
                $t->string('card_type', 20)->nullable()->after('authorization_no');
                $t->string('card_last4', 4)->nullable()->after('card_type');
                $t->string('referred_by', 150)->nullable()->after('card_last4');
                $t->string('procedure', 255)->nullable()->after('referred_by');
                $t->dateTime('planned_at')->nullable()->after('procedure');
            });
            // Arrivals before this: triaged at the desk when they have a level.
            DB::table('er_cases')->whereNotNull('triage_level')->update(['triage_status' => 'done']);
        }

        $permId = DB::table('permissions')->where('name', 'hms_triage')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'hms_triage', 'label' => 'Triage', 'description' => 'Hospital triage', 'created_at' => now(), 'updated_at' => now()]);

        foreach (DB::table('companies')->where('business_type', 'hospital')->whereNull('deleted_at')->pluck('id') as $cid) {
            foreach (['Triage', 'Emergency', 'Head Nurse'] as $role) {
                \App\Support\HospitalRoles::syncRole((int) $cid, $role);
            }
        }

        // Menu: Triage after the admission desk.
        $key = '/app/hospital/triage';
        if (Schema::hasTable('menu_positions')) {
            foreach (DB::table('menu_positions')->where('item_key', '/app/hospital/admission-desk')->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
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
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach (['Triage' => 'Triaje', 'Hms_triage_waiting' => 'Pacientes esperando triaje'] as $k => $es) {
                TranslationKeys::ensure($k, ['en' => $k === 'Hms_triage_waiting' ? 'Patients waiting for triage' : $k, 'es' => $es]);
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$k])->update(['es' => $es]);
            }
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }

        // Vitalis: a triage nurse to test with (employee login, standard password).
        foreach (DB::table('companies')->where('name', 'Vitalis')->whereNull('deleted_at')->pluck('id') as $cid) {
            $this->triageNurse((int) $cid);
        }
    }

    private function triageNurse(int $cid): void
    {
        $roleId = \App\Support\HospitalRoles::roleId($cid, 'Triage');
        if (! $roleId || DB::table('role_user as ru')->join('users as u', 'u.id', '=', 'ru.user_id')->where('ru.role_id', $roleId)->whereNull('u.deleted_at')->exists()) {
            return;
        }
        $dept = DB::table('departments')->where('company_id', $cid)->where('department', 'Personal asistencial')->value('id')
            ?? DB::table('departments')->where('company_id', $cid)->value('id');
        $designation = DB::table('designations')->where('company_id', $cid)->where('designation', 'Enfermera de triaje')->value('id')
            ?? DB::table('designations')->insertGetId(['company_id' => $cid, 'department_id' => $dept, 'designation' => 'Enfermera de triaje', 'created_at' => now(), 'updated_at' => now()]);
        $shift = DB::table('office_shifts')->where('company_id', $cid)->where('name', 'Turnos del hospital')->value('id')
            ?? DB::table('office_shifts')->where('company_id', $cid)->value('id');
        $email = 'triaje@'.\App\Support\CompanyLogins::slug($cid).'.test';
        $employeeId = DB::table('employees')->insertGetId([
            'company_id' => $cid, 'firstname' => 'Carmen', 'lastname' => 'Quispe Rojas', 'username' => 'Carmen Quispe Rojas',
            'email' => $email, 'phone' => '987654321', 'gender' => 'female', 'avatar' => 'no_avatar.png',
            'joining_date' => now()->subYear()->toDateString(), 'department_id' => $dept, 'designation_id' => $designation,
            'office_shift_id' => $shift, 'total_leave' => 18, 'remaining_leave' => 18, 'basic_salary' => 0,
            'employment_type' => 'full_time', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The login (CLAUDE.md: every employee has one), with the Triage role and the standard password.
        $userId = \App\Services\LinkedUserService::provision($cid, 'employee', 'Triage', 'Carmen Quispe Rojas', $email, '987654321');
        DB::table('employees')->where('id', $employeeId)->update(['user_id' => $userId]);
        DB::table('users')->where('id', $userId)->update(['password' => \Illuminate\Support\Facades\Hash::make(\App\Support\CompanyLogins::PASSWORDS['employee']), 'statut' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('item_key', '/app/hospital/triage')->delete();
        }
    }
};
