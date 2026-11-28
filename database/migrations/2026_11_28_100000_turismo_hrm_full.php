<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo: the whole HRM module (the user, 2026-11-28: "habilita el módulo de RRHH y dale acceso a director y
 * administración"). The module flag stays on; the HRM screens hidden on 2026-11-26 (turismo_menu_cleanup) are shown again
 * in Back Office -> Organización -> RRHH: Departamentos (moved into RRHH), Cargos, Turnos, Asistencia, Permisos y tipos,
 * Feriados, Planilla, Contratos de empleados and the attendance summary. Companies (/app/hrm/company) stays hidden: it is
 * the company's configuration, IT Admin only. Director and Manager (Administración: Mayte) get every HRM permission.
 * By company, role and permission names. Safe to run twice; down() hides the screens again and takes the added
 * permissions back.
 */
return new class extends Migration
{
    private const SHOW = ['/app/hrm/departments', '/app/hrm/designations', '/app/hrm/office_Shift', '/app/hrm/attendance',
        'group:Leave_request', '/app/hrm/leaves/list', '/app/hrm/leaves/type', '/app/hrm/holidays', '/app/hrm/payrolls',
        '/app/contracts', '/app/reports/attendance_report', 'custom_attendances', 'hrm', '/app/hrm/employees'];

    private const ROLES = ['Director', 'Manager'];

    private const PERMS = ['view_employee', 'add_employee', 'edit_employee', 'delete_employee', 'employee_view_all',
        'department', 'designation', 'office_shift', 'attendance', 'report_attendance_summary', 'leave', 'holiday',
        'payroll', 'contracts'];

    private function company(): int
    {
        return (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
    }

    public function up(): void
    {
        $cid = $this->company();
        if (! $cid) {
            return;
        }
        $now = now();

        foreach (DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->get(['id', 'module_flags']) as $s) {
            $flags = json_decode((string) $s->module_flags, true) ?: [];
            if (($flags['hrm'] ?? true) !== true) {
                $flags['hrm'] = true;
                DB::table('settings')->where('id', $s->id)->update(['module_flags' => json_encode($flags)]);
            }
        }

        DB::table('menu_positions')->where('company_id', $cid)->whereIn('item_key', self::SHOW)->update(['hidden' => 0, 'updated_at' => $now]);
        // Departamentos inside RRHH (it sat directly under Organización).
        $hrmMax = (int) DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'hrm')->max('sort_order');
        DB::table('menu_positions')->where('company_id', $cid)->where('item_key', '/app/hrm/departments')->where('parent_key', '!=', 'hrm')
            ->update(['parent_key' => 'hrm', 'sort_order' => $hrmMax + 1, 'updated_at' => $now]);

        $roles = DB::table('roles')->where('company_id', $cid)->whereIn('name', self::ROLES)->whereNull('deleted_at')->pluck('id');
        $pids = DB::table('permissions')->whereIn('name', self::PERMS)->pluck('id');
        foreach ($roles as $rid) {
            foreach ($pids as $pid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
        // A role-level hide of an HRM screen would undo this (lib/menuVisibility.js).
        $hides = DB::table('permissions')->whereIn('name', array_map(fn ($k) => 'menu_hide:'.$k, array_merge(self::SHOW, ['custom_company'])))->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $roles)->whereIn('permission_id', $hides)->delete();
    }

    public function down(): void
    {
        $cid = $this->company();
        if (! $cid) {
            return;
        }
        DB::table('menu_positions')->where('company_id', $cid)
            ->whereIn('item_key', array_diff(self::SHOW, ['hrm', '/app/hrm/employees', 'custom_attendances', 'group:Leave_request']))
            ->update(['hidden' => 1]);
        DB::table('menu_positions')->where('company_id', $cid)->where('item_key', '/app/hrm/departments')->update(['parent_key' => 'custom_company']);
        // Back to what 2026_11_23_170000 gave the Manager; the Director keeps what it had before then.
        $roles = DB::table('roles')->where('company_id', $cid)->whereIn('name', self::ROLES)->whereNull('deleted_at')->pluck('id');
        $pids = DB::table('permissions')->whereIn('name', ['department', 'designation', 'office_shift', 'contracts'])->pluck('id');
        $director = DB::table('roles')->where('company_id', $cid)->where('name', 'Director')->whereNull('deleted_at')->value('id');
        DB::table('permission_role')->where('role_id', $director)->whereIn('permission_id', $pids)->delete();
        DB::table('permission_role')->whereIn('role_id', $roles)
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'contracts')->pluck('id'))->delete();
    }
};
