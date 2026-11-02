<?php

namespace App\Support;

use App\Models\Employee;
use App\Services\Helpdesk\HelpdeskAccess;
use Illuminate\Http\Request;

/**
 * Employee details tabs: View / Edit per tab, per role (Users -> Roles,
 * permissions employee_tab_{tab}_{view|edit}; SuperAdmin always). Also the
 * company + own-record boundary for the endpoints behind the tabs (social,
 * experiences, bank accounts), which used to accept any employee id.
 */
class EmployeeTabs
{
    public const TABS = ['basic', 'company', 'social', 'experiences', 'accounts', 'user', 'assets', 'production', 'payments'];

    /** Basic information tab fields (employees table). */
    public const BASIC_FIELDS = ['firstname', 'lastname', 'gender', 'marital_status', 'birth_date', 'email', 'phone', 'country', 'city', 'province', 'zipcode', 'address'];

    /** Company tab fields. */
    public const COMPANY_FIELDS = ['employment_type', 'joining_date', 'leaving_date', 'total_leave', 'company_id', 'department_id', 'designation_id', 'office_shift_id', 'hourly_rate', 'basic_salary', 'pay_type', 'commission_rate', 'pay_effective_from',
        'staff_type', 'trip_rate'];

    public static function can($user, string $tab, string $action): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->type === 'SuperAdmin') {
            return true;
        }
        // Edit needs View too: no editing a tab you can't see.
        if ($action === 'edit' && ! HelpdeskAccess::can($user, "employee_tab_{$tab}_view")) {
            return false;
        }

        return HelpdeskAccess::can($user, "employee_tab_{$tab}_{$action}");
    }

    public static function abortUnless($user, string $tab, string $action): void
    {
        abort_unless(self::can($user, $tab, $action), 403, "You do not have permission to {$action} this employee's ".str_replace('_', ' ', $tab).' tab.');
    }

    /**
     * The employee behind a tab request, after the company boundary (a
     * non-SuperAdmin only their own company) and the own-record rule (only
     * your own record unless admin / "View all employees").
     */
    public static function employee(Request $request, $employeeId): Employee
    {
        $user = $request->user('api');
        $employee = Employee::whereNull('deleted_at')->findOrFail((int) $employeeId);
        if ($user->type !== 'SuperAdmin') {
            abort_unless((int) $employee->company_id === (int) $user->company_id, 403);
        }
        $viewAll = in_array(strtolower((string) $user->type), ['admin', 'superadmin'], true)
            || (bool) $user->roles()->first()?->permissions->contains('name', 'employee_view_all');
        abort_unless($viewAll || (int) $employee->user_id === (int) $user->id, 403, 'You can only see your own employee record.');

        return $employee;
    }

    /** {tab: {view, edit}} for the page. */
    public static function forUser($user): array
    {
        $out = [];
        foreach (self::TABS as $tab) {
            $out[$tab] = ['view' => self::can($user, $tab, 'view'), 'edit' => self::can($user, $tab, 'edit')];
        }

        return $out;
    }
}
