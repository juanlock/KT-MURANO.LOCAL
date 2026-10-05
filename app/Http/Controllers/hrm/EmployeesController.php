<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeAccount;
use App\Models\EmployeeExperience;
use App\Models\OfficeShift;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EmployeesController extends Controller
{
    use ScopesToCompany;

    /**
     * Who sees every employee of the company: admins, SuperAdmin, or a role
     * with "View all employees" (employee_view_all). Anyone else only sees
     * their own employee record (employees.user_id = them).
     */
    private function canViewAllEmployees($user): bool
    {
        if (! $user) {
            return false;
        }
        if (in_array(strtolower((string) $user->type), ['admin', 'superadmin'], true)) {
            return true;
        }

        return (bool) $user->roles()->first()?->permissions->contains('name', 'employee_view_all');
    }

    // A single employee record: only your own unless you may view all.
    private function abortUnlessOwnOrAll(Request $request, $employee): void
    {
        $user = $request->user('api');
        abort_unless($this->canViewAllEmployees($user) || (int) $employee->user_id === (int) $user->id, 403,
            'You can only see your own employee record.');
    }

    // ------------ GET ALL employees -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Employee::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $param = [
            0 => 'like',
            1 => 'like',
            2 => '=',

        ];
        $columns = [
            0 => 'username',
            1 => 'employment_type',
            2 => 'company_id',
        ];
        $data = [];

        $user = $request->user('api');

        $employees = Employee::with('company:id,name', 'office_shift:id,name', 'department:id,department', 'designation:id,designation')
            ->where('deleted_at', '=', null)
            ->where('leaving_date', null)
            // Company boundary: a SuperAdmin sees every company (optionally
            // narrowed via ?company_id=); everyone else only their own,
            // regardless of what the request sends.
            ->when($user->type !== 'SuperAdmin' || $request->filled('company_id'), function ($query) use ($request) {
                return $query->where('company_id', $this->resolveActingCompanyId($request));
            })
            // Without "View all employees": only their own record.
            ->when(! $this->canViewAllEmployees($user), fn ($q) => $q->where('user_id', $user->id));

        // Multiple Filter
        $Filtred = $helpers->filter($employees, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('firstname', 'LIKE', "%{$request->search}%")
                        ->orWhere('lastname', 'LIKE', "%{$request->search}%")
                        ->orWhere('username', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $employees->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $employees = $employees->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        // Each employee's login (linked users row) and its role, for the list.
        $logins = \App\Models\User::whereIn('id', $employees->pluck('user_id')->filter())
            ->get(['id', 'username', 'role_id', 'statut'])->keyBy('id');
        $roleNames = \App\Models\Role::whereIn('id', $logins->pluck('role_id')->filter())->pluck('name', 'id');
        // All positions of the page's employees (one employee, several departments / designations).
        $positions = $this->positionRows($employees->pluck('id')->all())->groupBy('employee_id');

        foreach ($employees as $employee) {
            $login = $employee->user_id ? $logins->get($employee->user_id) : null;
            $item['username'] = optional($login)->username;
            $item['user_id'] = optional($login)->id; // Username column links to Users -> Edit
            $item['role_name'] = $login ? ($roleNames[$login->role_id] ?? null) : null;
            $item['login_enabled'] = $login ? (int) $login->statut === 1 : false;

            $item['id'] = $employee->id;
            $item['firstname'] = $employee->firstname;
            $item['lastname'] = $employee->lastname;
            $item['phone'] = $employee->phone;
            $item['company_name'] = $employee['company']->name;
            $pos = $positions->get($employee->id);
            $item['department_name'] = $pos ? $pos->pluck('department')->filter()->unique()->implode(', ') : optional($employee['department'])->department;
            $item['designation_name'] = $pos ? $pos->pluck('designation')->filter()->unique()->implode(', ') : optional($employee['designation'])->designation;
            $item['office_shift_name'] = $employee['office_shift']->name;

            $data[] = $item;
        }

        $companies = Company::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'employees' => $data,
            'companies' => $companies,
            'totalRows' => $totalRows,
        ]);

    }

    // ---------------- Show Form Create Employee ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Employee::class);

        return response()->json([
            'companies' => $this->companyChoices($request),
            // System access card: roles per company (filtered by the picked company).
            'roles' => $this->rolesFor(null),
        ] + $this->positionOptions());
    }

    /**
     * Companies the form's Company dropdown offers (multicompany rule): an
     * Admin only their own; a SuperAdmin only the company picked in the header
     * switcher, or every company when none is picked / acting as AdminSystem
     * (999). $keepId (edit) keeps the employee's current company listed.
     */
    private function companyChoices(Request $request, $keepId = null)
    {
        $user = $request->user('api');
        $query = Company::where('deleted_at', '=', null);
        if ($user->type !== 'SuperAdmin') {
            $query->where('id', $user->company_id);
        } elseif ($request->filled('company_id') && (int) $request->input('company_id') !== 999) {
            $query->whereIn('id', array_filter([(int) $request->input('company_id'), $keepId ? (int) $keepId : null]));
        }

        return $query->orderBy('name')->get(['id', 'name']);
    }

    // ----------------Store  Employee ---------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Employee::class);

        // Company boundary: non-SuperAdmin admins can only create employees
        // within their own company, regardless of what the request sends.
        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            $request->merge(['company_id' => $actingUser->company_id]);
        }

        // Positions form: the first position fills the primary department / designation.
        $positions = $request->input('positions');
        if (is_array($positions) && ! empty($positions[0])) {
            $request->merge(['department_id' => $positions[0]['department_id'] ?? null, 'designation_id' => $positions[0]['designation_id'] ?? null]);
        }

        $this->validate($request, [
            'firstname' => 'required|string',
            'lastname' => 'nullable|string',
            'gender' => 'required',
            'company_id' => 'required',
            'department_id' => 'required',
            'designation_id' => 'required',
            'office_shift_id' => 'required',
        ]);

        // One person = one employee (one login): no second employee with the same name / email.
        if ($dup = $this->duplicateOf((int) $request['company_id'], (string) $request['firstname'], $request['lastname'], $request['email'])) {
            return response()->json([
                'message' => "{$dup->firstname} {$dup->lastname} is already an employee of this company (#{$dup->id}). Edit that employee and add the new department / designation there instead.",
                'errors' => ['firstname' => ["Already an employee (#{$dup->id}) -- add the position to that employee."]],
                'duplicate_id' => $dup->id,
            ], 422);
        }

        $data = [];
        $data['firstname'] = $request['firstname'];
        $data['lastname'] = $request['lastname'];
        $data['username'] = $request['firstname'].' '.$request['lastname'];
        $data['country'] = $request['country'];
        $data['email'] = $request['email'];
        $data['gender'] = $request['gender'];
        $data['phone'] = $request['phone'];
        $data['birth_date'] = $request['birth_date'];
        $data['company_id'] = $request['company_id'];
        $data['department_id'] = $request['department_id'];
        $data['designation_id'] = $request['designation_id'];
        $data['office_shift_id'] = $request['office_shift_id'];
        $data['joining_date'] = $request['joining_date'];

        \DB::transaction(function () use ($data, $request) {
            $employee = Employee::create($data);

            // Every employee gets a matching users row (type=employee) --
            // employees.user_id already existed as a nullable/unique FK for
            // this, nothing ever populated it.
            $employee->user_id = \App\Services\LinkedUserService::provision(
                (int) $data['company_id'], 'employee', 'Employee', trim($data['firstname'].' '.$data['lastname']), $data['email'], $data['phone']
            );
            $employee->save();
            $this->syncPositions($employee->fresh(), $request);
            // System access card: can sign in? role, login email, password, PIN.
            // Without it, the login is enabled only when the company has a
            // default employee password (Defaults -> Users); else disabled.
            if ($request->has('access')) {
                $this->applySystemAccess($employee->fresh(), $request);
            } else {
                $hasDefault = (bool) \App\Models\Setting::defaultPasswordHash((int) $data['company_id'], 'employee');
                \App\Models\User::whereKey($employee->user_id)->update(['statut' => $hasDefault ? 1 : 0]);
            }
            $this->applySalesRoles($employee->fresh(), $request);
        });

        return response()->json(['success' => true]);
    }

    // ------------ Photo (employee photo / linked user's avatar) -----------\\

    /**
     * POST employees/{id}/photo?for=employee|user  (file "photo")  -- upload
     * DELETE employees/{id}/photo?for=employee|user                  -- remove
     * employee: employees.avatar in files/company_{id}/employees/ (400 px);
     * user: the linked user's avatar in files/company_{id}/avatar/ (128 px,
     * same folder as Users). Same access as editing the employee; the old
     * file is deleted only when it lives in this company's folder.
     */
    public function photo(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);
        $employee = Employee::where('deleted_at', '=', null)->findOrFail($id);
        $this->abortUnlessOwnOrAll($request, $employee);
        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            abort_unless((int) $employee->company_id === (int) $actingUser->company_id, 403);
        }
        $cid = (int) $employee->company_id;
        $forUser = $request->input('for') === 'user';
        if ($forUser) {
            abort_unless($employee->user_id, 422, 'No user is linked to this employee.');
            $user = \App\Models\User::findOrFail($employee->user_id);
            abort_unless((int) $user->company_id === $cid, 403);
            $current = $user->avatar;
        } else {
            $current = $employee->avatar;
        }

        $path = 'no_avatar.png';
        if ($request->isMethod('post')) {
            $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:5120']);
            $dir = \App\Support\CompanyFiles::dir($cid, $forUser ? 'avatar' : 'employees');
            $size = $forUser ? 128 : 400;
            $name = \Illuminate\Support\Str::random(10).'-'.\App\Support\CompanyFiles::safeName(pathinfo($request->file('photo')->getClientOriginalName(), PATHINFO_FILENAME)).'.jpg';
            $img = \Intervention\Image\ImageManagerStatic::make($request->file('photo')->getRealPath());
            try {
                $img->orientate(); // phone photos: turn upright (needs the exif extension)
            } catch (\Throwable $e) {
            }
            $img->fit($size, $size)->save(public_path($dir.'/'.$name), 85, 'jpg');
            $path = $dir.'/'.$name;
        }

        // The old photo: deleted only when it is a file of this company.
        if ($current && str_starts_with((string) $current, 'files/company_'.$cid.'/') && ! str_contains((string) $current, '..') && is_file(public_path($current))) {
            @unlink(public_path($current));
        }
        if ($forUser) {
            $user->avatar = $path;
            $user->save();
        } else {
            $employee->avatar = $path;
            $employee->save();
        }

        return response()->json(['success' => true, 'avatar' => $path === 'no_avatar.png' ? null : $path]);
    }

    // ------------ Production (services done / payments received) -----------\\

    /**
     * GET employees/{id}/production?from&to -- what an employee produced and
     * what they were paid, in a date range (empty = everything):
     *  - services: bookings where they are the staff (not cancelled), plus
     *    sales where they are the sales agent that have no booking (a sale
     *    with a booking counts once, under the booking's staff);
     *  - payments: HRM payroll, plus expenses recorded from Zelle payments
     *    sent to them (email linked to the employee, e.g. 1099 contractors).
     * Same access as the employee record (own company; own record unless
     * the user may view all employees).
     */
    public function production(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Employee::class);
        $employee = Employee::where('deleted_at', '=', null)->findOrFail($id);
        $this->abortUnlessOwnOrAll($request, $employee);
        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            abort_unless((int) $employee->company_id === (int) $actingUser->company_id, 403);
        }
        $cid = (int) $employee->company_id;
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : null;
        $range = fn ($q, $col) => $q->when($from, fn ($q) => $q->where($col, '>=', $from))->when($to, fn ($q) => $q->where($col, '<=', $to));
        $db = \Illuminate\Support\Facades\DB::class;

        $services = collect();
        if ($employee->user_id) {
            $bookings = $range($db::table('bookings as b'), 'b.booking_date')
                ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->where('w.company_id', $cid)
                ->leftJoin('clients as c', 'c.id', '=', 'b.customer_id')
                ->leftJoin('sales as s', 's.id', '=', 'b.sale_id')
                ->where('b.sales_person_id', $employee->user_id)->whereNull('b.deleted_at')->where('b.status', '!=', 'cancelled')
                ->get(['b.id', 'b.Ref', 'b.booking_date as date', 'b.booking_time as time', 'b.product_name as service', 'b.amount', 'b.status',
                    'c.name as customer', 'b.sale_id', 's.Ref as sale_ref']);
            foreach ($bookings as $b) {
                $services->push(['source' => 'booking', 'id' => $b->id, 'ref' => $b->Ref, 'date' => $b->date, 'time' => $b->time,
                    'service' => $b->service, 'customer' => $b->customer, 'amount' => (float) $b->amount, 'status' => $b->status,
                    'sale_id' => $b->sale_id, 'sale_ref' => $b->sale_ref]);
            }
            $agentIds = $db::table('sales_agents')->where('company_id', $cid)->where('user_id', $employee->user_id)->pluck('id');
            if ($agentIds->isNotEmpty()) {
                $sales = $range($db::table('sales as s'), 's.date')->where('s.company_id', $cid)->whereIn('s.sales_agent_id', $agentIds)->whereNull('s.deleted_at')
                    ->whereNotExists(fn ($q) => $q->select($db::raw(1))->from('bookings')->whereColumn('bookings.sale_id', 's.id')->whereNull('bookings.deleted_at'))
                    ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
                    ->get(['s.id', 's.Ref', 's.date', 's.time', 's.GrandTotal', 's.statut', 'c.name as customer']);
                $names = $db::table('sale_details as d')->join('products as p', 'p.id', '=', 'd.product_id')->whereIn('d.sale_id', $sales->pluck('id'))
                    ->get(['d.sale_id', 'p.name'])->groupBy('sale_id')->map(fn ($g) => $g->pluck('name')->unique()->implode(', '));
                foreach ($sales as $s) {
                    $services->push(['source' => 'sale', 'id' => $s->id, 'ref' => $s->Ref, 'date' => $s->date, 'time' => $s->time,
                        'service' => $names[$s->id] ?? null, 'customer' => $s->customer, 'amount' => (float) $s->GrandTotal, 'status' => $s->statut,
                        'sale_id' => $s->id, 'sale_ref' => $s->Ref]);
                }
            }
        }

        $payments = collect();
        foreach ($range($db::table('payrolls as p'), 'p.date')->where('p.employee_id', $employee->id)->whereNull('p.deleted_at')
            ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->get(['p.id', 'p.Ref', 'p.date', 'p.amount', 'p.payment_status', 'm.name as method']) as $p) {
            $payments->push(['source' => 'payroll', 'id' => $p->id, 'ref' => $p->Ref, 'date' => $p->date, 'amount' => (float) $p->amount,
                'method' => $p->method, 'category' => 'Payroll', 'details' => $p->payment_status]);
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('office_emails')) {
            $expIds = $db::table('office_emails')->where('company_id', $cid)->where('party_type', 'employee')->where('party_id', $employee->id)
                ->where('linked_type', 'expense')->pluck('linked_id')->unique();
            foreach ($range($db::table('expenses as x'), 'x.date')->whereIn('x.id', $expIds)->whereNull('x.deleted_at')->whereNull('x.payroll_id')
                ->leftJoin('payment_methods as m', 'm.id', '=', 'x.payment_method_id')
                ->leftJoin('expense_categories as c', 'c.id', '=', 'x.expense_category_id')
                ->get(['x.id', 'x.Ref', 'x.date', 'x.amount', 'x.details', 'm.name as method', 'c.name as category']) as $x) {
                $payments->push(['source' => 'expense', 'id' => $x->id, 'ref' => $x->Ref, 'date' => $x->date, 'amount' => (float) $x->amount,
                    'method' => $x->method, 'category' => $x->category, 'details' => $x->details]);
            }
        }

        $services = $services->sortByDesc(fn ($r) => $r['date'].' '.$r['time'])->values();
        $payments = $payments->sortByDesc('date')->values();
        $produced = round($services->sum('amount'), 2);
        $paid = round($payments->sum('amount'), 2);

        return response()->json([
            'services' => $services,
            'payments' => $payments,
            'summary' => [
                'services_count' => $services->count(), 'services_total' => $produced,
                'payments_count' => $payments->count(), 'payments_total' => $paid,
                'payout_pct' => $produced > 0 ? round($paid / $produced * 100, 1) : null,
                'has_user' => (bool) $employee->user_id,
            ],
        ]);
    }

    // ------------ function show -----------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Employee::class);

        $employee = Employee::where('deleted_at', '=', null)->findOrFail($id);
        $this->abortUnlessOwnOrAll($request, $employee);

        // Non-superadmin admins can't open an employee from a different company.
        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            abort_unless((int) $employee->company_id === (int) $actingUser->company_id, 403);
        }

        // Multicompany: an Admin only gets their own company in the Company select.
        $companies = $this->companyChoices($request, $employee->company_id);
        $office_shifts = OfficeShift::where('company_id', $employee->company_id)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'name']);
        $departments = Department::where('company_id', $employee->company_id)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'department']);
        $designations = Designation::where('department_id', $employee->department_id)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'designation']);

        return response()->json([
            'employee' => $employee,
            'companies' => $companies,
            'office_shifts' => $office_shifts,
            'departments' => $departments,
            'designations' => $designations,
            'user' => $this->linkedUser($employee),
        ]);

    }

    /**
     * The user account linked to an employee (employees.user_id), for the
     * employee's User tab -- safe fields only (never password / PIN / tokens).
     */
    private function linkedUser($employee): ?array
    {
        if (! $employee->user_id) {
            return null;
        }
        $db = \Illuminate\Support\Facades\DB::class;
        $u = $db::table('users')->where('id', $employee->user_id)->first([
            'id', 'company_id', 'firstname', 'lastname', 'username', 'email', 'phone', 'avatar', 'type', 'role_id', 'statut',
            'is_sales_staff', 'is_all_warehouses', 'record_view', 'portal_enabled', 'preferred_locale', 'created_at', 'deleted_at',
        ]);
        if (! $u) {
            return null;
        }
        $row = (array) $u;
        $row['role'] = $u->role_id ? $db::table('roles')->where('id', $u->role_id)->value('name') : null;
        $row['company'] = $db::table('companies')->where('id', $u->company_id)->value('name');
        $row['warehouses'] = $u->is_all_warehouses ? [] : $db::table('user_warehouse as uw')->join('warehouses as w', 'w.id', '=', 'uw.warehouse_id')
            ->where('uw.user_id', $u->id)->orderBy('w.name')->pluck('w.name')->all();
        $row['sales_agents'] = $db::table('sales_agents')->where('user_id', $u->id)->whereNull('deleted_at')->pluck('name')->all();
        $row['last_login'] = \Illuminate\Support\Facades\Schema::hasTable('user_login_sessions')
            ? $db::table('user_login_sessions')->where('user_id', $u->id)->max('logged_in_at') : null;
        $row['bookings_count'] = $db::table('bookings')->where('sales_person_id', $u->id)->whereNull('deleted_at')->count();

        return $row;
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);

        $employee = Employee::where('deleted_at', '=', null)->findOrFail($id);
        $this->abortUnlessOwnOrAll($request, $employee);

        // Non-superadmin admins can't open an employee from a different company.
        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            abort_unless((int) $employee->company_id === (int) $actingUser->company_id, 403);
        }

        $companies = $this->companyChoices($request, $employee->company_id);
        $office_shifts = OfficeShift::where('company_id', $employee->company_id)->where('deleted_at', '=', null)->get(['id', 'name']);
        $departments = Department::where('company_id', $employee->company_id)->where('deleted_at', '=', null)->get(['id', 'department']);
        $designations = Designation::where('department_id', $employee->department_id)->where('deleted_at', '=', null)->get(['id', 'designation']);

        return response()->json([
            'employee' => $employee,
            'companies' => $companies,
            'office_shifts' => $office_shifts,
            'departments' => $departments,
            'designations' => $designations,
            // System access card: the employee's login + the roles to choose from.
            'access' => $this->systemAccessOf($employee),
            'roles' => $this->rolesFor(null),
            // Other modules card: Sales Staff (Seller picker) / Sales Agent (Commissions).
            'sales_roles' => $this->salesRolesOf($employee),
            // Positions: this employee's departments / designations + the options.
            'positions' => $this->positionRows([(int) $employee->id])->map(fn ($p) => ['department_id' => (int) $p->department_id, 'designation_id' => (int) $p->designation_id])->values(),
        ] + $this->positionOptions());
    }

    // ---------------- UPDATE Employee -------------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Employee::class);

        // Company boundary: non-superadmin admins can neither edit an
        // employee from a different company nor move this one to a
        // different company, no matter what the request says.
        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            $targetCompanyId = Employee::where('id', $id)->value('company_id');
            abort_unless((int) $targetCompanyId === (int) $actingUser->company_id, 403);
            $request->merge(['company_id' => $actingUser->company_id]);
        }

        // Positions form: the first position is the primary department / designation.
        $positions = $request->input('positions');
        if (is_array($positions) && ! empty($positions[0])) {
            $request->merge(['department_id' => $positions[0]['department_id'] ?? null, 'designation_id' => $positions[0]['designation_id'] ?? null]);
        }

        // Two screens save here: the short Edit Employee form (name, contact,
        // company, department, designation, shift, system access) and the full
        // Employee Details page (also leave, salary, address...). Only the
        // fields a request SENDS are validated/changed -- the short form used to
        // fail on "The total leave field is required." and, had it passed,
        // would have blanked every field it doesn't show.
        $this->validate($request, [
            'firstname' => 'required|string',
            'lastname' => 'nullable|string',
            'country' => 'nullable|string',
            'gender' => 'required',
            'phone' => 'nullable',
            'total_leave' => 'sometimes|nullable|numeric|min:0',
            'company_id' => 'required',
            'department_id' => 'required',
            'designation_id' => 'required',
            'office_shift_id' => 'required',
            'basic_salary' => 'nullable|numeric',
            'hourly_rate' => 'nullable|numeric',
        ]);

        $employee = Employee::findOrFail($id);
        $this->abortUnlessOwnOrAll($request, $employee);
        if ($request->filled('firstname')
            && ($dup = $this->duplicateOf((int) ($request['company_id'] ?: $employee->company_id), (string) $request['firstname'], $request['lastname'] ?? $employee->lastname, $request['email'] ?? null, (int) $employee->id))) {
            return response()->json([
                'message' => "{$dup->firstname} {$dup->lastname} is already an employee of this company (#{$dup->id}). One person = one employee: add the department / designation to one of them and remove the other.",
                'errors' => ['firstname' => ["Same person as employee #{$dup->id}."]],
                'duplicate_id' => $dup->id,
            ], 422);
        }
        $fields = ['firstname', 'lastname', 'country', 'email', 'gender', 'phone', 'birth_date', 'company_id', 'department_id',
            'designation_id', 'office_shift_id', 'joining_date', 'role_users_id', 'leaving_date', 'marital_status', 'employment_type',
            'city', 'province', 'zipcode', 'address', 'basic_salary', 'hourly_rate'];
        $data = [];
        foreach ($fields as $f) {
            if ($request->exists($f)) {
                $data[$f] = $request[$f];
            }
        }
        if (array_key_exists('leaving_date', $data) && ! $data['leaving_date']) {
            $data['leaving_date'] = null;
        }
        $data['username'] = trim(($data['firstname'] ?? $employee->firstname).' '.($data['lastname'] ?? $employee->lastname));

        // calculation of total_leave & remaining_leave (only when the leave is sent)
        if ($request->filled('total_leave')) {
            $total = (float) $request->total_leave;
            $remaining = $request->filled('remaining_leave') ? (float) $request->remaining_leave : (float) $employee->remaining_leave;
            if ((float) $employee->total_leave == 0) {
                $data['total_leave'] = $total;
                $data['remaining_leave'] = $total;
            } elseif ($total > $employee->total_leave) {
                $data['total_leave'] = $total;
                $data['remaining_leave'] = $remaining + ($total - $employee->total_leave);
            } elseif ($total < $employee->total_leave) {
                $data['total_leave'] = $total;
                $data['remaining_leave'] = $remaining - ($employee->total_leave - $total);
            } else {
                $data['total_leave'] = $total;
                $data['remaining_leave'] = $employee->remaining_leave;
            }
        }

        \DB::transaction(function () use ($employee, $data, $request) {
            $employee->update($data);
            $this->syncPositions($employee->fresh(), $request);
            $this->applySystemAccess($employee->fresh(), $request);
            $this->applySalesRoles($employee->fresh(), $request);
        });

        return response()->json(['success' => true]);
    }

    /**
     * Employee form -> "Other modules" (`sales_roles` in the request):
     *  - sales_staff: listed in the Seller / Salesperson picker of the sale
     *    form and the POS (users.is_sales_staff on the employee's login);
     *  - sales_agent: a Commissions sales agent linked to the employee's login
     *    (sales_agents.user_id). Remove = deactivate, never delete -- the
     *    agent's commissions / receipts stay.
     * Both need the employee's users row; one is created (login disabled) if
     * the employee has none yet. Keys not sent are left as they are.
     */
    private function applySalesRoles(Employee $employee, Request $request): void
    {
        $roles = $request->input('sales_roles');
        if (is_array($roles) && array_key_exists('service_technician', $roles)) {
            $this->applyServiceTechnician($employee, filter_var($roles['service_technician'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! is_array($roles) || ! array_intersect(['sales_staff', 'sales_agent'], array_keys($roles))) {
            return;
        }
        $companyId = (int) $employee->company_id;
        $name = trim($employee->firstname.' '.$employee->lastname);

        if (! $employee->user_id) {
            $wanted = filter_var($roles['sales_staff'] ?? false, FILTER_VALIDATE_BOOLEAN) || filter_var($roles['sales_agent'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $wanted) {
                return;
            }
            $employee->user_id = \App\Services\LinkedUserService::provision($companyId, 'employee', 'Employee', $name, $employee->email, $employee->phone);
            $employee->save();
            \App\Models\User::whereKey($employee->user_id)->update(['statut' => 0]);
        }
        $userId = (int) $employee->user_id;

        if (array_key_exists('sales_staff', $roles)) {
            \App\Models\User::whereKey($userId)->update(['is_sales_staff' => filter_var($roles['sales_staff'], FILTER_VALIDATE_BOOLEAN)]);
        }

        if (array_key_exists('sales_agent', $roles)) {
            $agent = $this->salesAgentOf($employee, true);
            if (filter_var($roles['sales_agent'], FILTER_VALIDATE_BOOLEAN)) {
                if (! $agent) {
                    \App\Models\SalesAgent::create([
                        'company_id' => $companyId, 'user_id' => $userId, 'name' => $name ?: $employee->username,
                        'email' => $employee->email, 'phone' => $employee->phone, 'is_active' => true,
                    ]);
                } else {
                    if ($agent->trashed()) {
                        $agent->restore();
                    }
                    $agent->user_id = $userId;
                    $agent->is_active = true;
                    $agent->save();
                }
            } elseif ($agent && ! $agent->trashed() && $agent->is_active) {
                $agent->is_active = false;
                $agent->save();
            }
        }
    }

    /**
     * The employee's sales agent in their company: the one linked to their
     * login, else (only when $adopt, i.e. about to link it) an unlinked agent
     * of the company with the employee's email -- agents made by hand on the
     * Sales Agents screen before this button existed.
     */
    private function salesAgentOf(Employee $employee, bool $adopt = false): ?\App\Models\SalesAgent
    {
        $q = fn () => \App\Models\SalesAgent::withTrashed()->where('company_id', (int) $employee->company_id)->orderByRaw('deleted_at IS NOT NULL')->orderByDesc('is_active');
        $agent = $employee->user_id ? $q()->where('user_id', $employee->user_id)->first() : null;
        if (! $agent && $adopt && filter_var((string) $employee->email, FILTER_VALIDATE_EMAIL)) {
            $agent = $q()->whereNull('user_id')->where('email', $employee->email)->first();
        }

        return $agent;
    }

    /**
     * Employee form -> "Service Technician": the employee is one of the
     * company's Service Job technicians (service_technicians.employee_id, no
     * login needed). Remove = deactivate (their jobs keep the technician).
     * A technician typed by hand with the same email is adopted. Only with
     * the company's Service module.
     */
    private function applyServiceTechnician(Employee $employee, bool $on): void
    {
        $companyId = (int) $employee->company_id;
        if (! \App\Support\ModuleFlags::enabled($companyId, 'service')) {
            return;
        }
        $tech = $this->technicianOf($employee, true);
        $name = trim($employee->firstname.' '.$employee->lastname) ?: $employee->username;
        if ($on) {
            if (! $tech) {
                \App\Models\ServiceTechnician::create([
                    'company_id' => $companyId, 'employee_id' => $employee->id, 'name' => $name,
                    'phone' => $employee->phone, 'email' => $employee->email, 'is_active' => true,
                ]);
            } else {
                $tech->forceFill(['employee_id' => $employee->id, 'is_active' => true, 'deleted_at' => null])->save();
            }
        } elseif ($tech && $tech->is_active && ! $tech->deleted_at) {
            $tech->forceFill(['is_active' => false])->save();
        }
    }

    /** The employee's technician: linked by employee_id, else (when $adopt) an unlinked one of the company with their email. */
    private function technicianOf(Employee $employee, bool $adopt = false): ?\App\Models\ServiceTechnician
    {
        $q = fn () => \App\Models\ServiceTechnician::where('company_id', (int) $employee->company_id)->orderByRaw('deleted_at IS NOT NULL')->orderByDesc('is_active');
        $tech = $q()->where('employee_id', $employee->id)->first();
        if (! $tech && $adopt && filter_var((string) $employee->email, FILTER_VALIDATE_EMAIL)) {
            $tech = $q()->whereNull('employee_id')->whereNull('deleted_at')->where('email', $employee->email)->first();
        }

        return $tech;
    }

    /** Sales Staff / Sales Agent / Service Technician status of an employee for the form. */
    private function salesRolesOf(Employee $employee): array
    {
        $agent = $this->salesAgentOf($employee);
        $tech = $this->technicianOf($employee);

        return [
            'sales_staff' => $employee->user_id ? (bool) \App\Models\User::whereKey($employee->user_id)->value('is_sales_staff') : false,
            'sales_agent' => $agent && ! $agent->trashed() && $agent->is_active,
            'service_technician' => $tech && ! $tech->deleted_at && (bool) $tech->is_active,
        ];
    }

    /**
     * Employee form -> "System access" (`access` in the request): the
     * employee's own login (their linked users row, type employee):
     *  - enabled: can sign in (users.statut); off = login disabled, nothing deleted;
     *  - role_id: the company's role that decides what they see/do;
     *  - email / password: the login (password blank = keep);
     *  - pin: cashier PIN for the POS (unique per company, blank = none).
     * No linked user yet + enabled -> the login is created now.
     */
    private function applySystemAccess(Employee $employee, Request $request): void
    {
        if (! $request->has('access') || ! is_array($request->input('access'))) {
            return;
        }
        $a = $request->input('access');
        $enabled = filter_var($a['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $companyId = (int) $employee->company_id;
        $roleId = ! empty($a['role_id']) ? (int) $a['role_id'] : null;
        $email = trim((string) ($a['email'] ?? ''));
        $password = (string) ($a['password'] ?? '');
        $pin = trim((string) ($a['pin'] ?? ''));
        $username = trim((string) ($a['username'] ?? ''));

        $errors = [];
        if ($username !== '' && ! preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            $errors['access.username'] = ['3-40 letters, numbers, dot, dash or underscore.'];
        } elseif ($username !== '' && \App\Models\User::where('username', $username)->when($employee->user_id, fn ($q) => $q->where('id', '<>', $employee->user_id))->exists()) {
            // users.username is unique across the whole system.
            $errors['access.username'] = ['This username is already taken.'];
        }
        if ($roleId && ! \App\Models\Role::whereKey($roleId)->where('company_id', $companyId)->whereNull('deleted_at')->exists()) {
            $errors['access.role_id'] = ['That role does not belong to the employee\'s company.'];
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['access.email'] = ['Enter a valid login email.'];
        }
        if ($password !== '' && strlen($password) < 6) {
            $errors['access.password'] = ['The password must be at least 6 characters.'];
        }
        if ($pin !== '' && ! preg_match('/^\d{4,8}$/', $pin)) {
            $errors['access.pin'] = ['The PIN must be 4 to 8 digits.'];
        }

        $user = $employee->user_id ? \App\Models\User::find($employee->user_id) : null;
        if ($email !== '' && \App\Models\User::where('email', $email)->whereNull('deleted_at')->when($user, fn ($q) => $q->where('id', '<>', $user->id))->exists()) {
            $errors['access.email'] = ['This email is already used by another user.'];
        }
        if ($pin !== '' && \App\Models\User::pinTaken($companyId, $pin, optional($user)->id)) {
            $errors['access.pin'] = ['This PIN is already used by another user of this company.'];
        }
        if (! $user && $enabled) {
            if ($email === '' && ! filter_var((string) $employee->email, FILTER_VALIDATE_EMAIL)) {
                $errors['access.email'] = ['A login email is required to give this employee access.'];
            }
            if ($password === '') {
                $errors['access.password'] = ['Set a password for the new login.'];
            }
            if (! $roleId) {
                $errors['access.role_id'] = ['Choose the role for this employee.'];
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        if (! $user) {
            if (! $enabled) {
                return; // no login and none wanted
            }
            $userId = \App\Services\LinkedUserService::provision(
                $companyId, 'employee', 'Employee', trim($employee->firstname.' '.$employee->lastname), $email ?: $employee->email, $employee->phone
            );
            $employee->user_id = $userId;
            $employee->save();
            $user = \App\Models\User::find($userId);
        }

        if ($roleId) {
            $user->role_id = $roleId;
            \App\Models\role_user::updateOrCreate(['user_id' => $user->id], ['role_id' => $roleId]);
        }
        if ($email !== '') {
            $user->email = $email;
        }
        if ($username !== '') {
            $user->username = $username;
        }
        if ($password !== '') {
            $user->password = \Illuminate\Support\Facades\Hash::make($password);
        }
        if (array_key_exists('pin', $a)) {
            $user->pin = $pin !== '' ? $pin : null;
        }
        $user->statut = $enabled ? 1 : 0;
        $user->save();
    }

    /** Departments + designations (with company) the Positions picker offers. */
    private function positionOptions(): array
    {
        $actor = auth('api')->user();
        $scope = fn ($q) => $q->whereNull('deleted_at')
            ->when($actor && $actor->type !== 'SuperAdmin', fn ($q2) => $q2->where('company_id', $actor->company_id));

        return [
            'all_departments' => $scope(Department::query())->orderBy('department')->get(['id', 'department', 'company_id']),
            'all_designations' => $scope(Designation::query())->orderBy('designation')->get(['id', 'designation', 'department_id', 'company_id']),
        ];
    }

    /** Positions (department + designation names) of these employees, primary first. */
    private function positionRows(array $employeeIds)
    {
        if (! $employeeIds) {
            return collect();
        }

        return \DB::table('employee_positions as p')
            ->leftJoin('departments as d', 'd.id', '=', 'p.department_id')
            ->leftJoin('designations as g', 'g.id', '=', 'p.designation_id')
            ->whereIn('p.employee_id', $employeeIds)
            ->orderByDesc('p.is_primary')->orderBy('p.id')
            ->get(['p.employee_id', 'p.department_id', 'p.designation_id', 'p.is_primary', 'd.department', 'g.designation']);
    }

    /**
     * Saves the employee's positions (`positions` = [{department_id,
     * designation_id}, ...], first = primary). The primary is mirrored on
     * employees.department_id / designation_id. Each designation must belong
     * to the employee's company and to the department it's listed with.
     * No `positions` in the request: the single department/designation sent
     * (if any) becomes / replaces the primary.
     */
    private function syncPositions(Employee $employee, Request $request): void
    {
        $companyId = (int) $employee->company_id;
        $list = $request->input('positions');
        if (! is_array($list)) {
            if (! $employee->designation_id) {
                return;
            }
            $list = [['department_id' => $employee->department_id, 'designation_id' => $employee->designation_id]];
            // keep the employee's other (secondary) positions
            $keep = \App\Models\EmployeePosition::where('employee_id', $employee->id)->where('is_primary', false)
                ->get(['department_id', 'designation_id'])->map(fn ($p) => ['department_id' => $p->department_id, 'designation_id' => $p->designation_id])->all();
            $list = array_merge($list, $keep);
        }

        $clean = [];
        foreach ($list as $i => $p) {
            $dep = (int) ($p['department_id'] ?? 0);
            $des = (int) ($p['designation_id'] ?? 0);
            if (! $dep || ! $des || isset($clean[$des])) {
                continue;
            }
            $ok = \DB::table('designations')->where('id', $des)->where('department_id', $dep)->where('company_id', $companyId)->whereNull('deleted_at')->exists()
                && \DB::table('departments')->where('id', $dep)->where('company_id', $companyId)->whereNull('deleted_at')->exists();
            if (! $ok) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "positions.{$i}" => ['That department / designation does not belong to this company.'],
                ]);
            }
            $clean[$des] = $dep;
        }
        if (! $clean) {
            throw \Illuminate\Validation\ValidationException::withMessages(['positions' => ['Add at least one department and designation.']]);
        }

        \App\Models\EmployeePosition::where('employee_id', $employee->id)->delete();
        $first = true;
        foreach ($clean as $des => $dep) {
            \App\Models\EmployeePosition::create(['employee_id' => $employee->id, 'department_id' => $dep, 'designation_id' => $des, 'is_primary' => $first]);
            if ($first) {
                Employee::whereKey($employee->id)->update(['department_id' => $dep, 'designation_id' => $des]);
            }
            $first = false;
        }
    }

    /**
     * One person = one employee: refuse a second employee in the same company
     * with the same full name -- add the extra department / designation to
     * the existing one instead.
     */
    private function duplicateOf(int $companyId, string $first, ?string $last, ?string $email, ?int $exceptId = null): ?Employee
    {
        $full = mb_strtolower(trim($first.' '.(string) $last));

        return Employee::whereNull('deleted_at')->where('company_id', $companyId)
            ->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))
            // Same full name only: staff often share the business email
            // (e.g. info@...), so email alone can't identify a person.
            ->whereRaw("LOWER(TRIM(CONCAT(firstname, ' ', COALESCE(lastname, '')))) = ?", [$full])
            ->first();
    }

    /** Login of an employee for the form (no password / PIN hash leak). */
    private function systemAccessOf(Employee $employee): array
    {
        $u = $employee->user_id ? \App\Models\User::find($employee->user_id) : null;

        return [
            'has_login' => (bool) $u,
            'enabled' => $u ? (int) $u->statut === 1 : false,
            'username' => optional($u)->username,
            'email' => $u && ! str_ends_with((string) $u->email, '@placeholder.murano.local') ? $u->email : ($employee->email ?: ''),
            'role_id' => optional($u)->role_id,
            'pin' => optional($u)->pin ?: '',
        ];
    }

    /** Roles an employee can be given, per company (Owner excluded). */
    private function rolesFor(?int $companyId)
    {
        $actor = auth('api')->user();

        return \App\Models\Role::whereNull('deleted_at')->where('name', '!=', 'Owner')
            ->when($actor && $actor->type !== 'SuperAdmin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('name')->get(['id', 'name', 'company_id']);
    }

    // ------------ Delete Employee -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Employee::class);

        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            $targetCompanyId = Employee::where('id', $id)->value('company_id');
            abort_unless((int) $targetCompanyId === (int) $actingUser->company_id, 403);
        }
        $this->abortUnlessOwnOrAll($request, Employee::findOrFail($id));

        Employee::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Employee::class);

        $selectedIds = $request->selectedIds;

        // Company boundary: a non-SuperAdmin can only bulk-delete employees
        // belonging to their own company.
        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            $selectedIds = Employee::whereIn('id', $selectedIds)->where('company_id', $actingUser->company_id)->pluck('id')->all();
        }
        // Without "View all employees": only their own record.
        if (! $this->canViewAllEmployees($actingUser)) {
            $selectedIds = Employee::whereIn('id', $selectedIds)->where('user_id', $actingUser->id)->pluck('id')->all();
        }

        foreach ($selectedIds as $employee_id) {
            Employee::whereId($employee_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);

    }

    public function Get_employees_by_department(Request $request)
    {
        // Company boundary: a non-SuperAdmin can only query a department
        // belonging to their own company, regardless of the id passed in.
        $actingUser = $request->user('api');
        if ($actingUser->type !== 'SuperAdmin') {
            $departmentCompanyId = Department::where('id', $request->id)->value('company_id');
            abort_unless((int) $departmentCompanyId === (int) $actingUser->company_id, 403);
        }

        $employees = Employee::where('department_id', $request->id)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'username']);

        return response()->json($employees);
    }

    public function Get_office_shift_by_company(Request $request)
    {
        // Company boundary: a non-SuperAdmin can only ever request their own
        // company's shifts, regardless of the id passed in.
        $actingUser = $request->user('api');
        $companyId = $actingUser->type === 'SuperAdmin' ? $request->id : $actingUser->company_id;

        $office_shifts = OfficeShift::where('company_id', $companyId)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'name']);

        return response()->json($office_shifts);
    }

    // details employee

    public function update_social_profile(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);

        $data = [];
        $data['skype'] = $request['skype'];
        $data['facebook'] = $request['facebook'];
        $data['whatsapp'] = $request['whatsapp'];
        $data['twitter'] = $request['twitter'];
        $data['linkedin'] = $request['linkedin'];

        Employee::whereId($id)->update($data);

        return response()->json(['success' => true]);
    }

    // -------------------- get_experiences_by_employee -------------\\

    public function get_experiences_by_employee(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', Employee::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;

        $experiences = EmployeeExperience::where('employee_id', $request->id)
            ->where('deleted_at', '=', null)
            ->orderBy('id', 'desc');

        $totalRows = $experiences->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $experiences = $experiences->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'totalRows' => $totalRows,
            'experiences' => $experiences,
        ]);

    }

    // -------------------- get_accounts_by_employee -------------\\

    public function get_accounts_by_employee(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', Employee::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;

        $accounts_bank = EmployeeAccount::where('employee_id', $request->id)
            ->where('deleted_at', '=', null)
            ->orderBy('id', 'desc');

        $totalRows = $accounts_bank->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $accounts_bank = $accounts_bank->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'totalRows' => $totalRows,
            'accounts_bank' => $accounts_bank,
        ]);

    }

    public function Get_employees_by_company(Request $request)
    {
        // Company boundary: a non-SuperAdmin can only ever request their own
        // company's employees, regardless of the id passed in.
        $actingUser = $request->user('api');
        $companyId = $actingUser->type === 'SuperAdmin' ? $request->id : $actingUser->company_id;

        $employees = Employee::where('company_id', $companyId)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'username']);

        return response()->json($employees);
    }
}
