<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Permission;
use App\Models\Role;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PermissionsController extends BaseController
{
    use ScopesToCompany;

    // ----------- GET ALL Roles --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Role::class);
        $companyId = $this->resolveActingCompanyId($request);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;

        $roles = Role::where('deleted_at', '=', null)
            ->where('company_id', $companyId)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $roles->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $roles = $roles->withCount('permissions')
            ->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'roles' => $roles,
            'totalRows' => $totalRows,
            'totalPermissions' => Permission::count(),
        ]);
    }

    // ----------- Store new Role --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Role::class);
        $companyId = $this->resolveActingCompanyId($request);

        try {
            request()->validate([
                'role.name' => 'required',
            ]);

            \DB::transaction(function () use ($request, $companyId) {

                // -- Create New Role
                $Role = new Role;
                $Role->company_id = $companyId;
                $Role->name = $request['role']['name'];
                $Role->label = $request['role']['name'];
                $Role->status = 0;
                $Role->description = $request['role']['description'];
                $Role->save();

                $role = Role::findOrFail($Role->id);
                $role->permissions()->detach();
                $permissions = $request->permissions;

                foreach ($permissions as $permission_slug) {
                    $perm = Permission::firstOrCreate(['name' => $permission_slug]);
                    $data[] = $perm->id;
                }

                $role->permissions()->attach($data);

            }, 10);

            return response()->json(['success' => true]);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ----------- Update Role --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Role::class);
        // A regular admin must never be able to update a different
        // company's role by tampering with the id in the URL.
        $companyId = $this->resolveActingCompanyId($request);
        abort_unless(
            optional(Role::where('deleted_at', null)->find($id))->company_id === $companyId,
            404
        );

        try {
            request()->validate([
                'role.name' => 'required',
            ]);

            \DB::transaction(function () use ($request, $id) {

                Role::whereId($id)->update($request['role']);

                $role = Role::findOrFail($id);
                $role->permissions()->detach();
                $permissions = $request->permissions;

                foreach ($permissions as $permission_slug) {

                    // get the permission object by name
                    $perm = Permission::firstOrCreate(['name' => $permission_slug]);
                    $data[] = $perm->id;
                }

                $role->permissions()->attach($data);

            }, 10);

            return response()->json(['success' => true]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

    }

    // ----------- Delete Role --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Role::class);
        $companyId = $this->resolveActingCompanyId($request);
        abort_unless(
            optional(Role::where('deleted_at', null)->find($id))->company_id === $companyId,
            404
        );

        Role::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Role::class);
        $companyId = $this->resolveActingCompanyId($request);

        $selectedIds = $request->selectedIds;
        // Scoped to the acting company -- a tampered id list can't reach
        // another company's roles.
        Role::whereIn('id', $selectedIds)
            ->where('company_id', $companyId)
            ->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    // ----------- GET ALL Roles without paginate --------------\\

    public function getRoleswithoutpaginate(Request $request)
    {
        $companyId = $this->resolveActingCompanyId($request);
        $roles = Role::where('deleted_at', null)->where('company_id', $companyId)->get(['id', 'name']);

        return response()->json($roles);
    }

    // ------------- Show Form Edit Permissions -----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Role::class);
        $companyId = $this->resolveActingCompanyId($request);

        $Role = Role::with('permissions')->where('deleted_at', '=', null)->findOrFail($id);
        // Owner is the one un-editable role, in every company (previously a
        // hardcoded id=1 check -- wrong once every company has its own
        // Owner row with a different id).
        abort_if($Role->name === 'Owner', 401);
        // A regular admin must never be able to open a different company's
        // role for editing by guessing/tampering with the id in the URL.
        abort_unless($Role->company_id === $companyId, 404);

        $item['name'] = $Role->name;
        $item['description'] = $Role->description;
        $data = [];
        foreach ($Role->permissions as $permission) {
            $data[] = $permission->name;
        }

        return response()->json([
            'permissions' => $data,
            'role' => $item,
        ]);
    }
}
