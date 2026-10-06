<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeExperience;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EmployeeExperienceController extends Controller
{
    public function index(Request $request)
    {
        //
    }

    // ----------- Store new Employee Experience --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Employee::class);
        // Company / own-record boundary + the Experiences tab's Edit permission.
        \App\Support\EmployeeTabs::employee($request, $request['employee_id']);
        \App\Support\EmployeeTabs::abortUnless($request->user('api'), 'experiences', 'edit');

        request()->validate([
            'title' => 'required|string',
            'company_name' => 'required|string',
            'start_date' => 'required',
            'end_date' => 'required',
            'employment_type' => 'required',
        ]);

        EmployeeExperience::create([
            'company_name' => $request['company_name'],
            'employee_id' => $request['employee_id'],
            'title' => $request['title'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'],
            'employment_type' => $request['employment_type'],
            'location' => $request['location'],
            'description' => $request['description'],
        ]);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -----------Update Employee Experience --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);
        // The experience's own employee (never moved to another one): boundary + tab Edit.
        $row = EmployeeExperience::whereNull('deleted_at')->findOrFail($id);
        \App\Support\EmployeeTabs::employee($request, $row->employee_id);
        \App\Support\EmployeeTabs::abortUnless($request->user('api'), 'experiences', 'edit');

        request()->validate([
            'title' => 'required|string',
            'company_name' => 'required|string',
            'start_date' => 'required',
            'end_date' => 'required',
            'employment_type' => 'required',
        ]);

        EmployeeExperience::whereId($id)->update([
            'company_name' => $request['company_name'],
            'title' => $request['title'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'],
            'employment_type' => $request['employment_type'],
            'location' => $request['location'],
            'description' => $request['description'],
        ]);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  Employee --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Employee::class);
        $row = EmployeeExperience::whereNull('deleted_at')->findOrFail($id);
        \App\Support\EmployeeTabs::employee($request, $row->employee_id);
        \App\Support\EmployeeTabs::abortUnless($request->user('api'), 'experiences', 'edit');

        EmployeeExperience::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }
}
