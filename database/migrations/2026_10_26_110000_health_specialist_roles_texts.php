<?php

use App\Support\HospitalRoles;
use App\Support\SpecialistRoles;
use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Health Specialist (2026-10-26): every specialist-clinic company (by
 * business type) gets its roles (SpecialistRoles: Recepción, Enfermería,
 * Médico especialista, Caja); the bell's clinic alerts and the page titles
 * in Spanish. Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Hms_clinic_ready' => ['Patients ready to go in', 'Pacientes listos para pasar'],
        'Hms_clinic_requests' => ['Appointments to schedule (follow-ups / referrals)', 'Citas por agendar (controles / interconsultas)'],
        'Hms_clinic_late' => ['Patients waiting for the doctor more than 15 min', 'Pacientes esperando al médico más de 15 min'],
        'Health specialist (specialist clinic)' => ['Health specialist (specialist clinic)', 'Consultorio de especialistas'],
    ];

    public function up(): void
    {
        if (Schema::hasColumn('companies', 'business_type') && Schema::hasTable('roles')) {
            foreach (DB::table('companies')->where('business_type', 'health_specialist')->whereNull('deleted_at')->pluck('id') as $cid) {
                DB::transaction(fn () => HospitalRoles::apply((int) $cid, SpecialistRoles::ROLES));
            }
        }
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach (self::TEXTS as $key => [$en, $es]) {
                TranslationKeys::ensure($key, ['en' => $en, 'es' => $es]);
                $row = DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->first();
                if ($row && trim((string) $row->es) === trim((string) ($row->en ?: $key))) {
                    DB::table('translations')->where('id', $row->id)->update(['es' => $es, 'updated_at' => now()]);
                }
            }
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }
    }

    public function down(): void
    {
    }
};
