<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DepartmentsController extends Controller
{
    use ScopesToCompany;

    // ----------- GET ALL  Department --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Department::class);
        $companyId = $this->resolveActingCompanyId($request);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;

        $departments = Department::leftjoin('employees', 'employees.id', '=', 'departments.department_head')
            ->join('companies', 'companies.id', '=', 'departments.company_id')
            ->where('departments.deleted_at', '=', null)
            ->where('departments.company_id', $companyId)
            ->select('departments.*', 'employees.username AS employee_head', 'companies.name AS company_name')

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('departments.department', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $departments->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $departments = $departments->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'departments' => $departments,
            'totalRows' => $totalRows,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Department::class);

        // A regular admin only ever creates a department for their own
        // company -- the picker is SuperAdmin-only (matching the "acting
        // company" model everywhere else); the field still exists in the
        // form because it also filters the Department Head dropdown.
        $companies = $request->user('api')->type === 'SuperAdmin'
            ? Company::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'name'])
            : Company::where('id', $this->resolveActingCompanyId($request))->get(['id', 'name']);

        return response()->json([
            'companies' => $companies,
        ]);

    }

    // ----------- Store new department --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Department::class);

        request()->validate([
            'department' => 'required|string',
            'company_id' => 'required',
        ]);

        // A regular admin can only ever create a department for their own
        // company -- ignore whatever company_id the request sent and force
        // it, rather than trusting a client-editable form field.
        $companyId = $request->user('api')->type === 'SuperAdmin'
            ? (int) $request['company_id']
            : $this->resolveActingCompanyId($request);

        Department::create([
            'department' => $request['department'],
            'company_id' => $companyId,
            'department_head' => $request['department_head'] ? $request['department_head'] : null,
        ]);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ------------ function edit -----------\\

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Department::class);
        // A regular admin must never be able to open a different company's
        // department for editing by guessing/tampering with the id in the URL.
        abort_unless(
            optional(Department::where('deleted_at', null)->find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        $companies = $request->user('api')->type === 'SuperAdmin'
            ? Company::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'name'])
            : Company::where('id', $this->resolveActingCompanyId($request))->get(['id', 'name']);

        return response()->json([
            'companies' => $companies,
        ]);

    }

    // -----------Update department --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Department::class);
        $companyId = $this->resolveActingCompanyId($request);
        abort_unless(
            optional(Department::where('deleted_at', null)->find($id))->company_id === $companyId,
            404
        );

        request()->validate([
            'department' => 'required|string',
            'company_id' => 'required',
        ]);

        // Same rule as store(): only SuperAdmin may move a department to a
        // different company.
        $newCompanyId = $request->user('api')->type === 'SuperAdmin'
            ? (int) $request['company_id']
            : $companyId;

        Department::whereId($id)->update([
            'department' => $request['department'],
            'company_id' => $newCompanyId,
            'department_head' => $request['department_head'] ? $request['department_head'] : null,
        ]);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  department --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Department::class);
        abort_unless(
            optional(Department::where('deleted_at', null)->find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        \DB::transaction(function () use ($id) {

            Department::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Department::class);
        $companyId = $this->resolveActingCompanyId($request);

        $selectedIds = $request->selectedIds;
        // Scoped to the acting company -- a tampered id list can't reach
        // another company's departments.
        Department::whereIn('id', $selectedIds)
            ->where('company_id', $companyId)
            ->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function Get_all_Departments(Request $request)
    {
        $companyId = $this->resolveActingCompanyId($request);
        $departments = Department::where('deleted_at', '=', null)->where('company_id', $companyId)->orderBy('id', 'desc')->get(['id', 'department']);

        return response()->json($departments);
    }
}
