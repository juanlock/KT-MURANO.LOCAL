<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CategorieController extends BaseController
{
    use ScopesToCompany;

    /**
     * GET /categories
     * Query params: page, limit, SortField, SortType, search
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Category::class);

        // Inputs with sane defaults
        $page = (int) $request->input('page', 1);
        $perPage = (string) $request->input('limit', '10');  // may be "-1"
        $sortField = (string) $request->input('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search', ''));

        // Whitelist fields to avoid SQL injection on orderBy
        $allowedSort = ['id', 'name', 'code', 'created_at', 'updated_at'];
        if (! in_array($sortField, $allowedSort, true)) {
            $sortField = 'id';
        }

        // Company scoping -- SuperAdmin may pass ?company_id= (a company
        // picker sends it); everyone else is locked to their own company.
        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $query = $this->scopeListToCompany(Category::query(), $request)
            ->whereNull('deleted_at')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            });

        // Total before paging (used by vue-good-table)
        $totalRows = (clone $query)->count();

        // Handle "show all" case
        if ($perPage === '-1') {
            $perPage = $totalRows > 0 ? $totalRows : 1;
        } else {
            $perPage = max(1, (int) $perPage);
        }

        $offSet = ($page * $perPage) - $perPage;

        $categories = $query
            ->orderBy($sortField, $sortType)
            ->offset($offSet)
            ->limit($perPage)
            ->get();
        // superadmin/AdminSystem: every company (2026-10-21) -- each row says whose it is.
        $categories = \App\Support\CompanyNames::attach($categories);

        return response()->json([
            'categories' => $categories,
            'totalRows' => $totalRows,
        ]);
    }

    /**
     * POST /categories
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Category::class);

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'code' => 'required|string|max:190',
            'icon' => 'nullable|string|max:64',
        ]);

        $category = Category::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'company_id' => $this->resolveActingCompanyId($request),
            'icon' => $validated['icon'] ?? null,
        ]);

        return response()->json([
            'success'  => true,
            'category' => $category,
        ], 201);
    }

    /**
     * GET /categories/{id}
     */
    public function show(Request $request, $id)
    {
        $category = Category::whereNull('deleted_at')->findOrFail($id);
        abort_unless($category->company_id === $this->resolveActingCompanyId($request), 404);

        return response()->json($category);
    }

    /**
     * PUT /categories/{id}
     */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Category::class);
        // A regular admin must never be able to update a different
        // company's category by tampering with the id in the URL.
        abort_unless(
            optional(Category::find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'code' => 'required|string|max:190',
            'icon' => 'nullable|string|max:64',
        ]);

        Category::whereId($id)->update([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? null,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /categories/{id}
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Category::class);
        abort_unless(
            optional(Category::find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        Category::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * POST /categories/delete/by_selection
     * Body: { selectedIds: [1,2,3] }
     */
    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Category::class);

        $selectedIds = (array) $request->input('selectedIds', []);
        if (empty($selectedIds)) {
            return response()->json(['success' => true]); // nothing to do
        }

        // Scoped to the acting company -- a selection can only ever have
        // come from this company's own (already-scoped) list, but this is
        // the last line of defense against a tampered request.
        Category::whereIn('id', $selectedIds)
            ->where('company_id', $this->resolveActingCompanyId($request))
            ->update([
                'deleted_at' => Carbon::now(),
            ]);

        return response()->json(['success' => true]);
    }
}
