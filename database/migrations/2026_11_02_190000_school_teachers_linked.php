<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Every person who signs in is linked to their record (user's rule, 2026-11-02),
 * like 2026_11_02_150000 for the hospital doctors: a school teacher without an
 * employee gets one -- through the Employee model, so its login is created --
 * with the role Profesor, in the department "Docentes" / designation
 * "Profesor" and the school's office shift. Demo teachers' new rows are tagged
 * as demo (Remove takes them) and their login gets the standard employee
 * password, like the demos' other people. Any school company; one transaction
 * each; safe to run twice (only teachers still without an employee).
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('teachers')->whereNull('deleted_at')->whereNull('employee_id')->distinct()->pluck('company_id');
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
        $demo = DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'teachers')->pluck('record_id')->map(fn ($v) => (int) $v)->all();
        $tag = function (string $table, int $id) use ($cid, $now) {
            DB::table('demo_records')->insert(['company_id' => $cid, 'module' => 'school', 'record_type' => $table, 'record_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        };
        $roleId = DB::table('roles')->where('company_id', $cid)->where('name', 'Profesor')->whereNull('deleted_at')->value('id');
        $pending = DB::table('teachers')->where('company_id', $cid)->whereNull('deleted_at')->whereNull('employee_id')->orderBy('id')->get();
        $anyDemo = $pending->contains(fn ($t) => in_array((int) $t->id, $demo, true));

        // HRM frame: tagged before the employees that point to it (demo rows are removed newest tag first).
        $deptId = DB::table('departments')->where('company_id', $cid)->where('department', 'Docentes')->whereNull('deleted_at')->value('id');
        if (! $deptId) {
            $deptId = DB::table('departments')->insertGetId(['company_id' => $cid, 'department' => 'Docentes', 'created_at' => $now, 'updated_at' => $now]);
            $anyDemo && $tag('departments', (int) $deptId);
        }
        $desId = DB::table('designations')->where('company_id', $cid)->where('department_id', $deptId)->where('designation', 'Profesor')->whereNull('deleted_at')->value('id');
        if (! $desId) {
            $desId = DB::table('designations')->insertGetId(['company_id' => $cid, 'department_id' => $deptId, 'designation' => 'Profesor', 'created_at' => $now, 'updated_at' => $now]);
            $anyDemo && $tag('designations', (int) $desId);
        }
        $shiftId = DB::table('office_shifts')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->value('id');
        if (! $shiftId) {
            $shiftId = DB::table('office_shifts')->insertGetId(['company_id' => $cid, 'name' => 'Horario escolar', 'created_at' => $now, 'updated_at' => $now]);
            $anyDemo && $tag('office_shifts', (int) $shiftId);
        }

        foreach ($pending as $t) {
            $name = trim((string) $t->name);
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $employee = Employee::create([
                'company_id' => $cid, 'firstname' => $first ?: 'Profesor', 'lastname' => $last ?: '-', 'username' => $name ?: 'Profesor '.$t->id,
                'email' => $t->email, 'phone' => $t->phone, 'gender' => $t->gender ?: 'other', 'avatar' => 'no_avatar.png', 'address' => $t->address,
                'department_id' => $deptId, 'designation_id' => $desId, 'office_shift_id' => $shiftId,
                'joining_date' => $t->joining_date ?: $now->toDateString(), 'employment_type' => 'full_time',
                'total_leave' => 18, 'remaining_leave' => 18, 'basic_salary' => (float) ($t->salary ?? 0),
            ]);
            $userId = (int) DB::table('employees')->where('id', $employee->id)->value('user_id');
            if ($userId && $roleId) {
                DB::table('role_user')->where('user_id', $userId)->delete();
                DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId]);
                DB::table('users')->where('id', $userId)->update(['role_id' => $roleId]);
            }
            DB::table('teachers')->where('id', $t->id)->update(['employee_id' => $employee->id, 'updated_at' => $now]);

            if (in_array((int) $t->id, $demo, true)) {
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
