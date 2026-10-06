<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAccount;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EmployeeAccountController extends Controller
{
    public function index(Request $request)
    {
        //
    }

    // ----------- Store new EmployeeAccount --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Employee::class);
        // Company / own-record boundary + the Bank accounts tab's Edit permission.
        \App\Support\EmployeeTabs::employee($request, $request['employee_id']);
        \App\Support\EmployeeTabs::abortUnless($request->user('api'), 'accounts', 'edit');

        $this->validate($request, [
            'bank_name' => 'required|string|max:255',
            'bank_branch' => 'required|string|max:255',
            'account_no' => 'required|string|max:255',
        ]);

        EmployeeAccount::create($request->only(['employee_id', 'bank_name', 'bank_branch', 'account_no', 'note']));

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -----------Update EmployeeAccount --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);
        // The account's own employee (never moved to another one): boundary + tab Edit.
        $row = EmployeeAccount::whereNull('deleted_at')->findOrFail($id);
        \App\Support\EmployeeTabs::employee($request, $row->employee_id);
        \App\Support\EmployeeTabs::abortUnless($request->user('api'), 'accounts', 'edit');

        $this->validate($request, [
            'bank_name' => 'required|string|max:255',
            'bank_branch' => 'required|string|max:255',
            'account_no' => 'required|string|max:255',
        ]);

        EmployeeAccount::whereId($id)->update($request->only(['bank_name', 'bank_branch', 'account_no', 'note']));

        return response()->json(['success' => true]);
    }

    // ----------- Delete  EmployeeAccount --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Employee::class);
        $row = EmployeeAccount::whereNull('deleted_at')->findOrFail($id);
        \App\Support\EmployeeTabs::employee($request, $row->employee_id);
        \App\Support\EmployeeTabs::abortUnless($request->user('api'), 'accounts', 'edit');

        EmployeeAccount::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }
}
