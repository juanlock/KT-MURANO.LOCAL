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

        // A SuperAdmin working as AdminSystem (999) sees every company's roles
        // (with their company); otherwise the acting company's only.
        $allCompanies = $this->superAdminAllCompanies($request, $companyId);
        $roles = Role::where('roles.deleted_at', '=', null)
            ->when(! $allCompanies, fn ($q) => $q->where('roles.company_id', $companyId))
            ->when($allCompanies && $request->filled('role_company_id'), fn ($q) => $q->where('roles.company_id', $request->role_company_id))
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
        // menu_hide:<route> rows only hide a menu item for the role -- they
        // aren't permissions, so they don't count on either side of "x / y"
        // (otherwise Owner, with everything, shows 323 / 326).
        $roles = $roles->withCount(['permissions' => fn ($q) => $q->where('permissions.name', 'not like', 'menu\_hide:%')])
            ->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        if ($allCompanies) {
            $names = \App\Models\Company::whereIn('id', $roles->pluck('company_id')->unique())->pluck('name', 'id');
            $roles->each(function ($r) use ($names) {
                $r->company_name = $names[$r->company_id] ?? ('#'.$r->company_id);
            });
        }

        return response()->json([
            'roles' => $roles,
            'totalRows' => $totalRows,
            'totalPermissions' => Permission::where('name', 'not like', 'menu\_hide:%')->count(),
            'all_companies' => $allCompanies,
            'companies' => $allCompanies ? \App\Models\Company::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    // SuperAdmin acting as AdminSystem (template company 999): every company's roles.
    private function superAdminAllCompanies(Request $request, $companyId): bool
    {
        return optional($request->user('api'))->type === 'SuperAdmin'
            && (int) $companyId === (int) \App\Models\Setting::TEMPLATE_COMPANY_ID;
    }

    // A SuperAdmin may open / edit / delete any company's role; an Admin only their company's.
    private function canTouchRole(Request $request, ?Role $role, $companyId): bool
    {
        if (! $role) {
            return false;
        }

        return optional($request->user('api'))->type === 'SuperAdmin' || (int) $role->company_id === (int) $companyId;
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
                $Role->home_path = self::homePath($request['role']['home_path'] ?? null);
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

    /** Role home page: an in-app path ("/service/daily"), else null (= normal start page). */
    private static function homePath($value): ?string
    {
        $v = trim((string) $value);

        return preg_match('#^/[A-Za-z0-9/_\-]{0,190}$#', $v) && $v !== '/' ? $v : null;
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
        abort_unless($this->canTouchRole($request, Role::where('deleted_at', null)->find($id), $companyId), 404);

        try {
            request()->validate([
                'role.name' => 'required',
            ]);

            \DB::transaction(function () use ($request, $id) {

                // Only name / description / home page (never company_id or anything else in the payload).
                Role::whereId($id)->update([
                    'name' => $request['role']['name'],
                    'description' => $request['role']['description'] ?? null,
                    'home_path' => self::homePath($request['role']['home_path'] ?? null),
                ]);

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
        abort_unless($this->canTouchRole($request, Role::where('deleted_at', null)->find($id), $companyId), 404);

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
            ->when(optional($request->user('api'))->type !== 'SuperAdmin', fn ($q) => $q->where('company_id', $companyId))
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
        $isSuperAdmin = optional($request->user('api'))->type === 'SuperAdmin';
        abort_if($Role->name === 'Owner' && ! $isSuperAdmin, 401);
        // A regular admin must never be able to open a different company's
        // role for editing by guessing/tampering with the id in the URL;
        // a SuperAdmin may open any company's.
        abort_unless($this->canTouchRole($request, $Role, $companyId), 404);

        $item['name'] = $Role->name;
        $item['description'] = $Role->description;
        $item['home_path'] = $Role->home_path;
        $item['company_id'] = $Role->company_id;
        $item['company_name'] = optional(\App\Models\Company::find($Role->company_id))->name;
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
