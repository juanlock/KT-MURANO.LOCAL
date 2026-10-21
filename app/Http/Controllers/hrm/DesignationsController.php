<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Designation;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DesignationsController extends Controller
{
    use ScopesToCompany;

    // ----------- GET ALL  Designations --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Designation::class);
        // superadmin/AdminSystem: every company (2026-10-21)
        $companyId = $this->listCompanyId($request);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $data = [];
        $designations = Designation::with('department', 'company')->where('deleted_at', '=', null)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('designation', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $designations->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $designations = $designations->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($designations as $designation) {

            $item['id'] = $designation->id;
            $item['designation'] = $designation->designation;
            $item['company_name'] = $designation->company_id == \App\Models\Setting::TEMPLATE_COMPANY_ID ? 'AdminSystem' : optional($designation['company'])->name;
            $item['company_id'] = $designation->company_id;
            $item['department_name'] = optional($designation['department'])->department;
            $item['department_id'] = $designation->department_id;

            $data[] = $item;
        }

        return response()->json([
            'designations' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Designation::class);

        // A regular admin only ever creates a designation for their own
        // company -- the picker is SuperAdmin-only.
        $companies = $request->user('api')->type === 'SuperAdmin'
            ? Company::where('deleted_at', '=', null)->get(['id', 'name'])
            : Company::where('id', $this->resolveActingCompanyId($request))->get(['id', 'name']);

        return response()->json([
            'companies' => $companies,
        ]);

    }

    // ----------- Store new designation --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Designation::class);

        request()->validate([
            'designation' => 'required|string',
            'company_id' => 'required',
            'department' => 'required',
        ]);

        // A regular admin can only ever create a designation for their own
        // company -- ignore whatever company_id the request sent and force
        // it, rather than trusting a client-editable form field.
        $companyId = $request->user('api')->type === 'SuperAdmin'
            ? (int) $request['company_id']
            : $this->resolveActingCompanyId($request);

        Designation::create([
            'designation' => $request['designation'],
            'company_id' => $companyId,
            'department_id' => $request['department'],
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
        $this->authorizeForUser($request->user('api'), 'update', Designation::class);
        abort_unless(
            optional(Designation::where('deleted_at', null)->find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        $companies = $request->user('api')->type === 'SuperAdmin'
            ? Company::where('deleted_at', '=', null)->get(['id', 'name'])
            : Company::where('id', $this->resolveActingCompanyId($request))->get(['id', 'name']);

        return response()->json([
            'companies' => $companies,
        ]);

    }

    // -----------Update designation --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Designation::class);
        $companyId = $this->resolveActingCompanyId($request);
        abort_unless(
            optional(Designation::where('deleted_at', null)->find($id))->company_id === $companyId,
            404
        );

        request()->validate([
            'designation' => 'required|string',
            'company_id' => 'required',
            'department' => 'required',
        ]);

        $newCompanyId = $request->user('api')->type === 'SuperAdmin'
            ? (int) $request['company_id']
            : $companyId;

        Designation::whereId($id)->update([
            'designation' => $request['designation'],
            'company_id' => $newCompanyId,
            'department_id' => $request['department'],
        ]);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  designation --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Designation::class);
        abort_unless(
            optional(Designation::where('deleted_at', null)->find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        \DB::transaction(function () use ($id) {

            Designation::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Designation::class);
        $companyId = $this->resolveActingCompanyId($request);

        $selectedIds = $request->selectedIds;
        Designation::whereIn('id', $selectedIds)
            ->where('company_id', $companyId)
            ->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function Get_designations_by_department(Request $request)
    {
        $companyId = $this->resolveActingCompanyId($request);
        $designations = Designation::where('department_id', $request->id)
            ->where('company_id', $companyId)
            ->where('deleted_at', '=', null)->get();

        return response()->json($designations);
    }
}
