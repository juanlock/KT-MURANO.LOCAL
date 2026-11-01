<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The roles every school company has (user's rule, 2026-10-20): Rector (the
 * school's admin), Secretaria, Profesor, Padre de familia and Alumno. Created
 * by name when missing -- never changed once they exist (the school may edit
 * them in Roles). Rector = the company's admin role (Admin-ALL, else Owner)
 * plus every School permission, within the settings a company may have
 * (SettingsAccess).
 *
 * Parents and students: the School module has no self-service view of "my
 * child's / my own" grades and attendance yet, so their roles only open the
 * Intranet and Documents (home page: Intranet).
 */
class SchoolRoles
{
    private const SCHOOL_ALL = [
        'school_dashboard', 'school_students_view', 'school_students_add', 'school_students_edit', 'school_students_delete',
        'school_teachers', 'school_academics', 'school_enrollment', 'school_attendance', 'school_timetable',
        'school_exams', 'school_fees', 'school_reports',
    ];

    /** name => [description, home page, permissions] (Rector's permissions are built in ensure()). */
    private const ROLES = [
        'Rector' => ['School head / administrator: the whole school module and the company settings.', '/school/dashboard', null],
        'Secretaria' => ['School office: students, enrolment, attendance, timetable, fees and reports.', '/school/dashboard', [
            'school_dashboard', 'school_students_view', 'school_students_add', 'school_students_edit', 'school_enrollment',
            'school_attendance', 'school_timetable', 'school_fees', 'school_reports',
            'documents_view', 'documents_add', 'intranet_view',
        ]],
        'Profesor' => ['Teacher: the class register (attendance), exams and results, the timetable and the students.', '/school/attendance', [
            'school_dashboard', 'school_students_view', 'school_attendance', 'school_exams', 'school_timetable',
            'documents_view', 'intranet_view',
        ]],
        'Padre de familia' => ['Parent / guardian: the school intranet (news, events, calendar) and shared documents.', '/intranet', [
            'intranet_view', 'documents_view',
        ]],
        'Alumno' => ['Student: the school intranet (news, events, calendar) and shared documents.', '/intranet', [
            'intranet_view', 'documents_view',
        ]],
    ];

    /** Create the missing school roles of the company. Returns the names created. */
    public static function ensure(int $companyId): array
    {
        $created = [];
        $now = now();
        foreach (self::ROLES as $name => [$description, $home, $perms]) {
            if (DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->exists()) {
                continue;
            }
            if ($perms === null) {
                $adminRole = DB::table('roles')->where('company_id', $companyId)->whereIn('name', [CompanyRoles::ADMIN, 'Admin-ALL', 'Owner'])
                    ->whereNull('deleted_at')->orderByRaw('name = ? desc', [CompanyRoles::ADMIN])->value('id');
                $perms = array_merge(self::SCHOOL_ALL, $adminRole
                    ? DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                        ->where('role_id', $adminRole)->pluck('permissions.name')->all()
                    : []);
            }
            $perms = SettingsAccess::filter($companyId, array_values(array_unique($perms)));
            $roleId = DB::table('roles')->insertGetId([
                'company_id' => $companyId, 'name' => $name, 'label' => $name, 'description' => $description,
                'status' => 0, 'home_path' => $home, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $permIds = [];
            foreach ($perms as $p) {
                $permIds[] = DB::table('permissions')->where('name', $p)->value('id')
                    ?? DB::table('permissions')->insertGetId(['name' => $p, 'label' => $p, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (array_chunk(array_unique($permIds), 500) as $chunk) {
                DB::table('permission_role')->insert(array_map(fn ($pid) => ['permission_id' => $pid, 'role_id' => $roleId], $chunk));
            }
            $created[] = $name;
        }

        return $created;
    }
}
