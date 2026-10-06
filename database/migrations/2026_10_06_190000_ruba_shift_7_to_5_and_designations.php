<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ruba (company 2, user's request 2026-10-06): the crew -- 1 supervisor
 * (ManagerRG) + 2 operators (EmployeeRG, EmployeeRG2) -- works Monday to
 * Friday 7:00 AM - 5:00 PM. Ruba's shift "RegularHours 7-4" becomes
 * "RegularHours 7-5" and is the shift of the three; the operators get the
 * "Operator" designation (they were all "manager").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('companies')->where('id', 2)->exists()) {
            return;
        }
        $week = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $values = ['name' => 'RegularHours 7-5', 'updated_at' => now()];
        foreach ($week as $d) {
            $values[$d.'_in'] = '07:00AM';
            $values[$d.'_out'] = '17:00PM';
        }
        $shiftId = DB::table('office_shifts')->where('company_id', 2)->whereNull('deleted_at')->whereIn('name', ['RegularHours 7-4', 'RegularHours 7-5'])->value('id');
        if ($shiftId) {
            DB::table('office_shifts')->where('id', $shiftId)->update($values);
        } else {
            $shiftId = DB::table('office_shifts')->insertGetId($values + ['company_id' => 2, 'created_at' => now()]);
        }

        DB::table('employees')->where('company_id', 2)->whereNull('deleted_at')
            ->whereIn('firstname', ['ManagerRG', 'EmployeeRG', 'EmployeeRG2'])->update(['office_shift_id' => $shiftId]);

        $operator = DB::table('designations')->where('company_id', 2)->whereNull('deleted_at')->where('designation', 'Operator')->first(['id', 'department_id']);
        if ($operator) {
            DB::table('employees')->where('company_id', 2)->whereNull('deleted_at')->whereIn('firstname', ['EmployeeRG', 'EmployeeRG2'])
                ->update(['designation_id' => $operator->id, 'department_id' => $operator->department_id]);
        }
    }

    public function down(): void
    {
    }
};
