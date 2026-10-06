<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Header -> "Clock in / Clock out" (user's request, 2026-10-06): employees
 * record their own attendance (permission attendance_self). New Era: StaffNE
 * and ManagerNE (the manager can also record anyone's day in HRM -> Attendance).
 */
return new class extends Migration
{
    public function up(): void
    {
        $pid = DB::table('permissions')->where('name', 'attendance_self')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'attendance_self', 'label' => 'attendance_self', 'description' => 'Clock in / out (own)', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->where('company_id', 3)->whereIn('name', ['StaffNE', 'ManagerNE'])->whereNull('deleted_at')->pluck('id') as $roleId) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
            }
        }
        if (! DB::table('translations')->where('key', 'Attendance_Self')->exists()) {
            DB::table('translations')->insert(['key' => 'Attendance_Self', 'is_default' => 1, 'en' => 'Clock in / out (own)', 'es' => 'Marcar entrada / salida (propia)', 'created_at' => now(), 'updated_at' => now()]);
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }
    }

    public function down(): void
    {
    }
};
