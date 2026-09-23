<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New translation keys for the Users "Type" create-form field
 * (UserForm.vue) -- Admin/SuperAdmin didn't exist yet as standalone labels
 * (only compound keys like Admin_Note did), and the auto-provisioning
 * hint/warning strings are new to this feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'Admin' => ['en' => 'Admin', 'es' => 'Administrador'],
            'SuperAdmin' => ['en' => 'Super Admin', 'es' => 'Super Administrador'],
            'AutoProvision_Hint' => [
                'en' => 'A matching {type} record will be created automatically for this user, with the {type} role.',
                'es' => 'Se creará automáticamente un registro de {type} para este usuario, con el rol de {type}.',
            ],
            'Warning_Employee_Missing_HR_Setup' => [
                'en' => 'User created, but no Employee record was made: this company needs at least one Department, Designation, and Office Shift set up first.',
                'es' => 'Usuario creado, pero no se generó el registro de Empleado: primero hay que configurar al menos un Departamento, un Cargo y un Turno de Oficina para esta empresa.',
            ],
        ];

        foreach ($rows as $key => $translations) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('translations')->insert([
                'key' => $key,
                'is_default' => 1,
                'en' => $translations['en'],
                'es' => $translations['es'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('translations')->whereIn('key', [
            'Admin', 'SuperAdmin', 'AutoProvision_Hint', 'Warning_Employee_Missing_HR_Setup',
        ])->delete();
    }
};
