<?php

namespace App\Http\Controllers;

use App\Models\ServiceChecklistCategory;
use App\Models\ServiceChecklistItem;
use App\Models\ServiceJob;
use Illuminate\Http\Request;

class ServiceChecklistController extends BaseController
{
    // Multicompany: checklist categories and items belong to a company (company_id).
    use \App\Http\Controllers\Concerns\ScopesServiceJobsToCompany;

    private function categories()
    {
        return $this->rc(ServiceChecklistCategory::query(), 'service_checklist_categories.company_id')->whereNull('service_checklist_categories.deleted_at');
    }

    private function items()
    {
        return $this->rc(ServiceChecklistItem::query(), 'service_checklist_items.company_id')->whereNull('service_checklist_items.deleted_at');
    }

    private function companyForNew(): int
    {
        $companyId = $this->serviceCompanyId();
        abort_unless($companyId, 422, 'Pick a company in the header first.');

        return $companyId;
    }

    /** An item's category must be one of the company's. */
    private function assertCategory($categoryId): void
    {
        abort_if($categoryId && ! $this->categories()->whereKey($categoryId)->exists(), 422, 'That checklist category does not belong to your company.');
    }

    // -------- Categories: list (with optional pagination & search) -------\\
    public function categoriesIndex(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $query = $this->categories()
            ->withCount(['items' => function ($q) {
                $q->whereNull('deleted_at');
            }]);

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $perPage = (int) $request->get('limit', 0);
        if ($perPage > 0) {
            $pageStart = (int) $request->get('page', 1);
            $offSet = ($pageStart * $perPage) - $perPage;
            $total = $query->count();
            $rows = $query->orderBy('name')
                ->offset($offSet)
                ->limit($perPage)
                ->get();

            return response()->json([
                'categories' => $rows,
                'totalRows' => $total,
            ]);
        }

        return response()->json([
            'categories' => $query->orderBy('name')->get(),
        ]);
    }

    public function categoriesStore(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', ServiceJob::class);

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $category = ServiceChecklistCategory::create([
            'company_id' => $this->companyForNew(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function categoriesUpdate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $category = $this->categories()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $category->update($validated);

        return response()->json(['success' => true]);
    }

    public function categoriesDestroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ServiceJob::class);

        $category = $this->categories()->findOrFail($id);
        $category->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // -------- Items: list / CRUD -------\\
    public function itemsIndex(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $query = $this->items();

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->get('category_id'));
        }

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $perPage = (int) $request->get('limit', 0);
        if ($perPage > 0) {
            $pageStart = (int) $request->get('page', 1);
            $offSet = ($pageStart * $perPage) - $perPage;
            $total = $query->count();
            $rows = $query->orderBy('sort_order')
                ->orderBy('name')
                ->offset($offSet)
                ->limit($perPage)
                ->get();

            return response()->json([
                'items' => $rows,
                'totalRows' => $total,
            ]);
        }

        return response()->json([
            'items' => $query->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function itemsStore(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', ServiceJob::class);

        $validated = $request->validate([
            'category_id' => 'nullable|integer',
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        $this->assertCategory($validated['category_id'] ?? null);

        $item = ServiceChecklistItem::create([
            'company_id' => $this->companyForNew(),
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['success' => true, 'data' => $item], 201);
    }

    public function itemsUpdate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $item = $this->items()->findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'nullable|integer',
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        $this->assertCategory($validated['category_id'] ?? null);

        $item->update($validated);

        return response()->json(['success' => true]);
    }

    public function itemsDestroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ServiceJob::class);

        $item = $this->items()->findOrFail($id);
        $item->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // -------- Options for forms (categories + items) -------\\
    public function options(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $categories = $this->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $items = $this->items()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $result = [];
        foreach ($categories as $category) {
            $result[] = [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'items' => $items->where('category_id', $category->id)
                    ->values()
                    ->map(function (ServiceChecklistItem $item) {
                        return [
                            'id' => $item->id,
                            'name' => $item->name,
                            'description' => $item->description,
                            'sort_order' => $item->sort_order,
                        ];
                    })
                    ->all(),
            ];
        }

        return response()->json([
            'categories' => $result,
        ]);
    }
}

















