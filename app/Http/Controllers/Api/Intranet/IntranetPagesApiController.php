<?php

namespace App\Http\Controllers\Api\Intranet;

use App\Models\IntranetPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Intranet Management -> Pages (intranet_manage, acting company only).
 * Slug is unique per company (built from the title when left empty).
 *
 *  GET    intranet-admin/pages
 *  GET    intranet-admin/pages/{id}
 *  POST   intranet-admin/pages          {title, slug?, content, published}
 *  POST   intranet-admin/pages/reorder  {ids}
 *  PUT    intranet-admin/pages/{id}
 *  DELETE intranet-admin/pages/{id}
 */
class IntranetPagesApiController extends IntranetBaseController
{
    public function index(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $rows = IntranetPage::where('company_id', $companyId)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'company_id', 'title', 'slug', 'published', 'sort_order', 'created_at', 'updated_at']);

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);

        return response()->json($this->own(IntranetPage::class, $companyId, $id));
    }

    private function validated(Request $request, int $companyId, ?IntranetPage $page = null): array
    {
        $request->merge([
            'published' => $request->boolean('published', $page ? $page->published : true),
            'slug' => Str::slug((string) ($request->input('slug') ?: $request->input('title'))),
        ]);
        $data = $request->validate([
            'title' => 'required|string|max:190',
            'slug' => [
                'required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('intranet_pages', 'slug')->where('company_id', $companyId)->ignore($page?->id),
            ],
            'content' => 'nullable|string|max:2000000',
            'published' => 'boolean',
        ]);
        $data['content'] = $this->purify($data['content'] ?? null);

        return $data;
    }

    public function store(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $data = $this->validated($request, $companyId);
        $data['sort_order'] = (int) IntranetPage::where('company_id', $companyId)->max('sort_order') + 1;

        return response()->json(IntranetPage::create($data + ['company_id' => $companyId]), 201);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $page = $this->own(IntranetPage::class, $companyId, $id);
        $page->fill($this->validated($request, $companyId, $page))->save();

        return response()->json($page);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $this->own(IntranetPage::class, $companyId, $id)->delete();

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $ids = $request->validate(['ids' => 'required|array|max:1000', 'ids.*' => 'integer'])['ids'];
        $own = IntranetPage::where('company_id', $companyId)->whereIn('id', $ids)->pluck('id')->flip();
        $i = 0;
        foreach ($ids as $id) {
            if (isset($own[$id])) {
                IntranetPage::whereKey($id)->update(['sort_order' => ++$i]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
