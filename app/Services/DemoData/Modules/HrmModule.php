<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HRM examples: an office shift, departments -> designations -> employees,
 * then holidays, leave requests, a few days of attendance and payroll.
 *
 * Detection tables are the HRM activity tables only (holidays, attendance,
 * leaves): a fresh company already owns one department/designation/employee
 * row for its admin user, which must not count as "real HRM data". Payrolls
 * have no company_id, so they're left out of detection (a check would be
 * install-wide).
 */
class HrmModule extends DemoModule
{
    public function key(): string
    {
        return 'hrm';
    }

    public function label(): string
    {
        return 'HRM';
    }

    public function dataTables(): array
    {
        return ['holidays', 'attendances', 'leaves'];
    }

    public function requiredTables(): array
    {
        return ['office_shifts', 'departments', 'designations', 'employees', 'holidays', 'leave_types', 'leaves', 'attendances', 'payrolls'];
    }

    public function generate(DemoContext $ctx): array
    {
        $counts = [];

        // Office shift, Mon-Fri 09:00-17:00 (stored as "09:00AM" like the form does).
        $shift = ['name' => 'Standard 9-5 (demo)'];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            $shift[$day.'_in'] = '09:00AM';
            $shift[$day.'_out'] = '05:00PM';
        }
        $shiftId = $ctx->insert('office_shifts', $shift);
        $counts['Office shifts'] = 1;

        // Departments -> designations.
        $structure = [
            'Sales' => ['Sales Representative', 'Sales Manager'],
            'Operations' => ['Warehouse Clerk'],
            'Finance' => ['Accountant'],
        ];
        $positions = []; // [department_id, designation_id, title]
        foreach ($structure as $dept => $titles) {
            $deptId = $ctx->insert('departments', ['department' => $dept, 'department_head' => null]);
            foreach ($titles as $title) {
                $desId = $ctx->insert('designations', ['department_id' => $deptId, 'designation' => $title]);
                $positions[] = [$deptId, $desId, $title];
            }
        }
        $counts['Departments'] = count($structure);
        $counts['Designations'] = count($positions);

        // Employees (no linked login user: user_id stays null).
        $employees = []; // id => [department_id, salary]
        $used = [];
        for ($i = 0; $i < 5; $i++) {
            do {
                [$first, $last] = $ctx->personName();
            } while (isset($used[$first.$last]));
            $used[$first.$last] = true;
            [$deptId, $desId, $title] = $positions[$i % count($positions)];
            $salary = $ctx->pick([2800, 3200, 3600, 4200, 5000]);
            $id = $ctx->insert('employees', [
                'user_id' => null,
                'firstname' => $first,
                'lastname' => $last,
                'username' => $first.' '.$last,
                'email' => strtolower($first.'.'.$last).'.'.random_int(100, 999).'@example.com',
                'phone' => '+1 555 '.random_int(100, 999).' '.random_int(1000, 9999),
                'country' => 'United States',
                'city' => $ctx->pick(['Miami', 'Orlando', 'Tampa']),
                'gender' => in_array($first, ['Sarah', 'Fatima', 'Aisha', 'Nadia', 'Leila', 'Julia', 'Emma'], true) ? 'female' : 'male',
                'avatar' => 'no_avatar.png',
                'birth_date' => Carbon::now()->subYears(random_int(24, 50))->subDays(random_int(0, 360))->toDateString(),
                'joining_date' => Carbon::now()->subDays(random_int(120, 900))->toDateString(),
                'department_id' => $deptId,
                'designation_id' => $desId,
                'office_shift_id' => $shiftId,
                'total_leave' => 18,
                'remaining_leave' => 15,
                'hourly_rate' => round($salary / 160, 2),
                'basic_salary' => $salary,
                'employment_type' => $i === 4 ? 'part_time' : 'full_time',
                'marital_status' => $ctx->pick(['single', 'married']),
            ]);
            $employees[$id] = [$deptId, $salary];
        }
        $counts['Employees'] = count($employees);

        // Holidays.
        $year = (int) date('Y');
        foreach ([
            ['New Year\'s Day', "$year-01-01", "$year-01-01"],
            ['Independence Day', "$year-07-04", "$year-07-04"],
            ['Christmas Break', "$year-12-24", "$year-12-26"],
        ] as [$title, $start, $end]) {
            $ctx->insert('holidays', ['title' => $title, 'start_date' => $start, 'end_date' => $end, 'description' => 'Company holiday']);
        }
        $counts['Holidays'] = 3;

        // Leave types are install-wide (no company_id): reuse existing ones,
        // create the basics only when the table is empty.
        $leaveTypes = DB::table('leave_types')->whereNull('deleted_at')->pluck('id')->all();
        if (! $leaveTypes) {
            foreach (['Annual Leave', 'Sick Leave', 'Personal Leave'] as $t) {
                $leaveTypes[] = $ctx->insert('leave_types', ['title' => $t]);
            }
            $counts['Leave types'] = 3;
        }

        // Leave requests.
        $empIds = array_keys($employees);
        $leaveStatuses = ['approved', 'pending', 'rejected', 'approved'];
        foreach ($leaveStatuses as $n => $status) {
            $empId = $empIds[$n % count($empIds)];
            $start = Carbon::now()->addDays(random_int(-45, 30))->startOfDay();
            $days = random_int(1, 3);
            $ctx->insert('leaves', [
                'employee_id' => $empId,
                'department_id' => $employees[$empId][0],
                'leave_type_id' => $ctx->pick($leaveTypes),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
                'days' => (string) $days,
                'reason' => $ctx->pick(['Family event', 'Medical appointment', 'Vacation', 'Personal matters']),
                'attachment' => 'no_image.png',
                'half_day' => 0,
                'status' => $status,
            ]);
        }
        $counts['Leave requests'] = count($leaveStatuses);

        // Attendance: the last 2 working days for every employee.
        $att = 0;
        $day = Carbon::now()->subDay();
        $workdays = [];
        while (count($workdays) < 2) {
            if (! $day->isWeekend()) {
                $workdays[] = $day->toDateString();
            }
            $day->subDay();
        }
        foreach ($workdays as $date) {
            foreach ($empIds as $empId) {
                $lateMin = $ctx->pick([0, 0, 0, 5, 15]);
                $outMin = $ctx->pick([0, 0, 30, -20]);
                $in = Carbon::parse("$date 09:00")->addMinutes($lateMin);
                $out = Carbon::parse("$date 17:00")->addMinutes($outMin);
                $work = $in->diff($out);
                $ctx->insert('attendances', [
                    'user_id' => $ctx->userId,
                    'employee_id' => $empId,
                    'date' => $date,
                    'clock_in' => $in->format('H:i'),
                    'clock_in_ip' => '',
                    'clock_out' => $out->format('H:i'),
                    'clock_out_ip' => '',
                    'clock_in_out' => 0,
                    'late_time' => sprintf('00:%02d', $lateMin),
                    'depart_early' => $outMin < 0 ? sprintf('00:%02d', -$outMin) : '00:00',
                    'overtime' => $outMin > 0 ? sprintf('00:%02d', $outMin) : '00:00',
                    'total_work' => sprintf('%02d:%02d', $work->h, $work->i),
                    'total_rest' => '00:00',
                    'status' => 'present',
                ]);
                $att++;
            }
        }
        $counts['Attendance records'] = $att;

        // Payroll: last month's salary per employee. Ref continues the
        // app's "PS_<n>" sequence (PayrollController::getNumberOrder).
        $last = DB::table('payrolls')->latest('id')->value('Ref');
        $seq = ($last && str_contains($last, '_')) ? (int) explode('_', $last)[1] : 0;
        $methodId = DB::table('payment_methods')->whereNull('deleted_at')->orderBy('id')->value('id');
        $payDate = Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        foreach ($employees as $empId => [$deptId, $salary]) {
            $ctx->insert('payrolls', [
                'user_id' => $ctx->userId,
                'Ref' => 'PS_'.(++$seq),
                'date' => $payDate,
                'employee_id' => $empId,
                'account_id' => null,
                'amount' => $salary,
                'payment_method_id' => $methodId,
                'payment_status' => 'paid',
            ]);
        }
        $counts['Payrolls'] = count($employees);

        return $counts;
    }
}
