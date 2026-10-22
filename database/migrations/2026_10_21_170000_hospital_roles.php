<?php

use App\Support\HospitalRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Hospital companies get the hospital roles (user's rule, 2026-10-21): the old
 * roles removed (their users -> "Personal sin rol"), Emergencia, Admisión,
 * Médico, Enfermero, Jefe de enfermería, Farmacia, Caja / Facturación,
 * Laboratorio and Quirófano created; Administrador, Paciente (cliente),
 * Proveedor and Personal sin rol kept (App\Support\HospitalRoles). By business
 * type, never by id. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'business_type') || ! Schema::hasTable('roles')) {
            return;
        }
        foreach (DB::table('companies')->where('business_type', 'hospital')->whereNull('deleted_at')->pluck('id') as $cid) {
            DB::transaction(fn () => HospitalRoles::apply((int) $cid));
        }
    }

    public function down(): void
    {
    }
};
