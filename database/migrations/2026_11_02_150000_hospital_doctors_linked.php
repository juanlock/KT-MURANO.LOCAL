<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Every person who signs in is linked to their record (user's rule, 2026-11-02:
 * only the SuperAdmin and the company admins have no employee / supplier /
 * customer behind them). Vitalis's doctors (demo made before the hospital demo
 * linked its staff) had no employee and no login: each doctor without an employee
 * gets one -- through the Employee model, so its login is created -- with the role
 * Doctor, in the department "Personal asistencial" / designation "Médico", like
 * HospitalModule::makeStaffAndCare. Demo doctors' new rows are tagged as demo
 * (Remove takes them) and their login gets the standard employee password, like
 * the demos' other people. Any hospital company; one transaction each; safe to
 * run twice (only doctors still without an employee).
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('doctors')->whereNull('deleted_at')->whereNull('employee_id')->distinct()->pluck('company_id');
        foreach ($companies as $cid) {
            $cid = (int) $cid;
            if (! DB::table('companies')->where('id', $cid)->whereNull('deleted_at')->exists()) {
                continue;
            }
            DB::transaction(fn () => $this->linkCompany($cid));
        }
    }

    private function linkCompany(int $cid): void
    {
        $now = now();
        $demoDoctors = DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'doctors')->pluck('record_id')->map(fn ($v) => (int) $v)->all();
        $tag = function (string $table, int $id) use ($cid, $now) {
            DB::table('demo_records')->insert(['company_id' => $cid, 'module' => 'hospital', 'record_type' => $table, 'record_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        };
        $roleId = \App\Support\HospitalRoles::roleId($cid, 'Doctor');
        $es = (DB::table('settings')->where('company_id', $cid)->value('default_language') ?: 'es') === 'es';

        $deptName = $es ? 'Personal asistencial' : 'Clinical staff';
        $deptId = DB::table('departments')->where('company_id', $cid)->where('department', $deptName)->whereNull('deleted_at')->value('id');
        $newDept = ! $deptId;
        $deptId = $deptId ?: DB::table('departments')->insertGetId(['company_id' => $cid, 'department' => $deptName, 'created_at' => $now, 'updated_at' => $now]);
        $desName = $es ? 'Médico' : 'Doctor';
        $desId = DB::table('designations')->where('company_id', $cid)->where('department_id', $deptId)->where('designation', $desName)->whereNull('deleted_at')->value('id');
        $newDes = ! $desId;
        $desId = $desId ?: DB::table('designations')->insertGetId(['company_id' => $cid, 'department_id' => $deptId, 'designation' => $desName, 'created_at' => $now, 'updated_at' => $now]);
        // employees.office_shift_id is required: the company's hospital shift, else its first one, else a new one.
        $shiftName = $es ? 'Turnos del hospital' : 'Hospital shifts';
        $shiftId = DB::table('office_shifts')->where('company_id', $cid)->whereNull('deleted_at')->orderByRaw('name = ? desc', [$shiftName])->orderBy('id')->value('id');
        $newShift = ! $shiftId;
        $shiftId = $shiftId ?: DB::table('office_shifts')->insertGetId(['company_id' => $cid, 'name' => $shiftName, 'created_at' => $now, 'updated_at' => $now]);
        $pending = DB::table('doctors')->where('company_id', $cid)->whereNull('deleted_at')->whereNull('employee_id')->orderBy('id')->get();
        // Demo rows are removed newest tag first: the department / designation are
        // tagged before the employees that point to them.
        if ($pending->contains(fn ($d) => in_array((int) $d->id, $demoDoctors, true))) {
            if ($newDept) {
                $tag('departments', (int) $deptId);
            }
            if ($newDes) {
                $tag('designations', (int) $desId);
            }
            if ($newShift) {
                $tag('office_shifts', (int) $shiftId);
            }
        }

        foreach ($pending as $d) {
            $female = (bool) preg_match('/^Dra\.?\s/i', $d->name);
            $name = trim(preg_replace('/^Dra?\.?\s*/i', '', $d->name));
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $employee = Employee::create([
                'company_id' => $cid, 'firstname' => $first, 'lastname' => $last ?: '-', 'username' => $name,
                'email' => $d->email, 'phone' => $d->phone, 'gender' => $female ? 'female' : 'male', 'avatar' => 'no_avatar.png',
                'department_id' => $deptId, 'designation_id' => $desId, 'office_shift_id' => $shiftId, 'joining_date' => now()->subYears(2)->toDateString(),
                'employment_type' => 'full_time', 'total_leave' => 18, 'remaining_leave' => 18, 'basic_salary' => 0,
            ]);
            $userId = (int) DB::table('employees')->where('id', $employee->id)->value('user_id');
            if ($userId && $roleId) {
                DB::table('role_user')->where('user_id', $userId)->delete();
                DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId]);
                DB::table('users')->where('id', $userId)->update(['role_id' => $roleId]);
            }
            DB::table('doctors')->where('id', $d->id)->update(['employee_id' => $employee->id, 'updated_at' => $now]);

            if (in_array((int) $d->id, $demoDoctors, true)) {
                $tag('employees', (int) $employee->id);
                if ($userId) {
                    $tag('users', $userId);
                    DB::table('users')->where('id', $userId)->update([
                        'password' => \Illuminate\Support\Facades\Hash::make(\App\Support\CompanyLogins::PASSWORDS['employee']), 'statut' => 1,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Not undone automatically: Remove demo data (Settings -> Demo Data) takes the demo ones.
    }
};
