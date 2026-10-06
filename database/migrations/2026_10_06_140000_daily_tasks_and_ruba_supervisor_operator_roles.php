<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Tasks (service jobs of the day) + per-role home page + Ruba's field
 * roles (user's request, 2026-10-06):
 *  - permissions: daily_tasks (open Daily Tasks: own jobs, start / complete
 *    with a note), daily_tasks_all (everyone's jobs), daily_tasks_assign
 *    (assign / reassign the technician). Every role that already has
 *    service_jobs gets all three.
 *  - roles.home_path: where Home / the dashboard takes that role's users
 *    (null = the company's normal start page).
 *  - Ruba (company 2): "SupervisorRG" -- the manager: all service jobs + Daily
 *    Tasks for everyone with assigning, own employee record (view); home =
 *    Daily Tasks. "OperatorRG" -- field employees: Daily Tasks, own jobs only,
 *    start / complete, no assigning; home = Daily Tasks.
 *    ManagerrgR13 -> SupervisorRG; EmployeergR97, Employeerg2R26 -> OperatorRG.
 */
return new class extends Migration
{
    private const PERMS = ['daily_tasks', 'daily_tasks_all', 'daily_tasks_assign'];

    private const KEYS = [
        'Daily_Tasks' => ['Daily Tasks', 'Tareas del día'],
        'Daily_Tasks_All' => ['Daily Tasks: everyone\'s jobs', 'Tareas del día: trabajos de todos'],
        'Daily_Tasks_Assign' => ['Daily Tasks: assign jobs', 'Tareas del día: asignar trabajos'],
    ];

    private const EMPLOYEE_OWN = [
        'view_employee',
        'employee_tab_basic_view', 'employee_tab_company_view', 'employee_tab_social_view',
        'employee_tab_experiences_view', 'employee_tab_accounts_view', 'employee_tab_user_view',
        'employee_tab_production_view', 'employee_tab_payments_view',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'home_path')) {
            Schema::table('roles', function (Blueprint $t) {
                $t->string('home_path', 191)->nullable()->after('description');
            });
        }

        $ids = [];
        foreach (self::PERMS as $p) {
            $ids[$p] = DB::table('permissions')->where('name', $p)->value('id')
                ?: DB::table('permissions')->insertGetId(['name' => $p, 'label' => $p, 'description' => $p, 'created_at' => now(), 'updated_at' => now()]);
        }
        $sj = DB::table('permissions')->where('name', 'service_jobs')->value('id');
        foreach (DB::table('permission_role')->where('permission_id', $sj)->pluck('role_id') as $roleId) {
            foreach ($ids as $pid) {
                $this->grant((int) $roleId, (int) $pid);
            }
        }

        foreach (self::KEYS as $key => [$en, $es]) {
            if (! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }

        // Ruba's roles (only where company 2 exists).
        if (! DB::table('settings')->where('company_id', 2)->exists()) {
            return;
        }
        $supervisor = $this->role(2, 'SupervisorRG', 'Supervisor RG', 'Manager / supervisor: all service jobs and Daily Tasks (assigns jobs), own employee record. Home: Daily Tasks.',
            array_merge(['service_jobs', 'daily_tasks', 'daily_tasks_all', 'daily_tasks_assign'], self::EMPLOYEE_OWN));
        $operator = $this->role(2, 'OperatorRG', 'Operator RG', 'Field operator: Daily Tasks only -- own jobs, start and complete, no assigning. Home: Daily Tasks.',
            ['daily_tasks']);

        $assign = ['ManagerrgR13' => $supervisor, 'EmployeergR97' => $operator, 'Employeerg2R26' => $operator];
        foreach ($assign as $username => $roleId) {
            $uid = DB::table('users')->where('company_id', 2)->where('username', $username)->value('id');
            if (! $uid) {
                continue;
            }
            DB::table('role_user')->where('user_id', $uid)->delete();
            DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
            // users.role_id too: the edit forms show / save it, and an old value
            // there was written back to role_user on the next save.
            DB::table('users')->where('id', $uid)->update(['role_id' => $roleId]);
        }
    }

    private function role(int $companyId, string $name, string $label, string $description, array $perms): int
    {
        $id = DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->value('id');
        if (! $id) {
            $id = DB::table('roles')->insertGetId([
                'company_id' => $companyId, 'name' => $name, 'label' => $label, 'description' => $description,
                'home_path' => '/service/daily', 'status' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $permIds = DB::table('permissions')->whereIn('name', $perms)->pluck('id');
        foreach ($permIds as $pid) {
            $this->grant((int) $id, (int) $pid);
        }

        return (int) $id;
    }

    /** permission_role has no unique key: check before inserting. */
    private function grant(int $roleId, int $permissionId): void
    {
        if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $roles = DB::table('roles')->where('company_id', 2)->whereIn('name', ['SupervisorRG', 'OperatorRG'])->pluck('id');
        $employeeRole = DB::table('roles')->where('company_id', 2)->where('name', 'Employee')->whereNull('deleted_at')->value('id');
        if ($employeeRole) {
            DB::table('role_user')->whereIn('role_id', $roles)->update(['role_id' => $employeeRole]);
        }
        DB::table('permission_role')->whereIn('role_id', $roles)->delete();
        DB::table('roles')->whereIn('id', $roles)->delete();
        $pids = DB::table('permissions')->whereIn('name', self::PERMS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $pids)->delete();
        DB::table('permissions')->whereIn('id', $pids)->delete();
        DB::table('translations')->whereIn('key', array_keys(self::KEYS))->delete();
        if (Schema::hasColumn('roles', 'home_path')) {
            Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('home_path'));
        }
    }
};
